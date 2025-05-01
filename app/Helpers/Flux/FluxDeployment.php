<?php

declare(strict_types=1);

namespace App\Helpers\Flux;

use App\Exceptions\FluxException;
use App\Models\Projects\Deployments\Deployment;
use App\Models\Projects\Deployments\DeploymentLink;
use App\Models\Projects\Deployments\ReservedPort;
use App\Models\Projects\Templates\TemplatePort;
use Exception;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;

/**
 * Class FluxDeployment.
 *
 * This class is the helper for the Flux deployment.
 *
 * @author Marcel Menk <marcel.menk@ipvx.io>
 */
class FluxDeployment
{
    /**
     * Generate a deployment.
     *
     * @param Deployment $deployment
     * @param array      $data
     * @param array      $secretData
     * @param bool       $replaceExisting
     *
     * @return object
     */
    public static function generate(Deployment $deployment, array $data = [], array $secretData = [], bool $replaceExisting = false)
    {
        FluxRepository::open($deployment->cluster);

        if (Storage::disk('local')->exists($deployment->path) && !$replaceExisting) {
            throw new FluxException('Forbidden', 403);
        }

        if ($replaceExisting) {
            Storage::disk('local')->deleteDirectory($deployment->path);
        }

        $deploymentDirectoryStatus = Storage::disk('local')->makeDirectory($deployment->path);

        if (!$deploymentDirectoryStatus) {
            throw new FluxException('Server Error', 500);
        }

        $reservedPorts = $deployment->ports()->whereNotNull('claim')->get();
        $portClaims    = $deployment->template->ports()
            ->whereNotNull('claim')
            ->get()
            ->mapWithKeys(function (TemplatePort $port) use ($deployment, $reservedPorts) {
                $reservedPort = $reservedPorts->where('claim', '=', $port->claim)->first();

                if (!$reservedPort) {
                    $reservedPort = ReservedPort::create([
                        'deployment_id' => $deployment->id,
                        'group'         => $port->group,
                        'claim'         => $port->claim,
                        'port'          => ReservedPort::random($port->group),
                    ]);
                }

                return [$reservedPort->claim => $reservedPort->port];
            })
            ->toArray();

        $deployment->template->fullTree->each(function ($item) use ($deployment, $data, $secretData, $portClaims) {
            if ($item->type === 'file') {
                self::createFile($item, $deployment, $data, $secretData, $portClaims);
            } elseif ($item->type === 'folder') {
                self::createFolder($item, $deployment, $data, $secretData, $portClaims);
            }
        });

        if ($deployment->template->netpol) {
            $rules = collect();

            if ($deployment->ingressAsTarget) {
                $rules = $deployment->ingressAsTarget->filter(function (DeploymentLink $link) {
                    return $link->source?->uuid;
                });
            }

            $ruleContent = '';

            if ($rules?->isNotEmpty()) {
                $rules->each(function (DeploymentLink $link) use (&$ruleContent) {
                    $ruleContent .= '
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: ' . $link->source?->uuid . '';
                });
            }

            // Generate netpol.yaml content
            $networkPolicyContent = '---
kind: NetworkPolicy
apiVersion: networking.k8s.io/v1
metadata:
  name: ingress
  namespace: ' . $deployment->uuid . '
spec:
  podSelector:
    matchLabels:
  ingress:
  - from:' . $ruleContent . '
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: ' . $deployment->uuid . '
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: ' . $deployment->cluster->utilityNamespace?->name . ($deployment->cluster->ingressNamespace?->name && $deployment->cluster->utilityNamespace?->name !== $deployment->cluster->ingressNamespace?->name ? '
    - namespaceSelector:
        matchLabels:
          kubernetes.io/metadata.name: ' . $deployment->cluster->ingressNamespace?->name : '');

            // Deploy netpol.yaml
            $networkPolicyDeployed = Storage::disk('local')->put($deployment->path . '/netpol.yaml', $networkPolicyContent);

            // Add netpol.yaml to customization file
            $kustomizationContent  = preg_replace("/resources:\r\n/", "resources:\r\n- netpol.yaml\r\n", Storage::get($deployment->path . '/kustomization.yaml'));
            $kustomizationContent  = preg_replace("/resources:\n/", "resources:\n- netpol.yaml\n", $kustomizationContent);
            $networkPolicyAppended = Storage::disk('local')->put($deployment->path . '/kustomization.yaml', $kustomizationContent);

            if (
                !$networkPolicyDeployed ||
                !$networkPolicyAppended
            ) {
                throw new FluxException('Server Error', 500);
            }
        }

        $commit = FluxRepository::push($deployment, $replaceExisting ? 'update' : 'creation');

        FluxRepository::close();

        return $commit;
    }

    /**
     * Delete a deployment.
     *
     * @param Deployment $deployment
     *
     * @return object
     */
    public static function delete(Deployment $deployment)
    {
        FluxRepository::open($deployment->cluster);

        if (!Storage::disk('local')->exists($deployment->path)) {
            throw new FluxException('Not Found', 404);
        }

        if (!Storage::disk('local')->deleteDirectory($deployment->path)) {
            throw new FluxException('Server Error', 500);
        }

        $commit = FluxRepository::push($deployment, 'deletion');

        FluxRepository::close();

        return $commit;
    }

    /**
     * Create a folder.
     *
     * @param object     $item
     * @param Deployment $deployment
     * @param array      $data
     * @param array      $secretData
     * @param array      $portClaims
     */
    private static function createFolder(object $item, Deployment $deployment, array $data = [], array $secretData = [], array $portClaims = [])
    {
        $path = $deployment->path . $item->object->path;

        Storage::disk('local')->makeDirectory($path);

        $item->children?->each(function ($child) use ($deployment, $data, $secretData, $portClaims) {
            if ($child->type === 'file') {
                self::createFile($child, $deployment, $data, $secretData, $portClaims);
            } elseif ($child->type === 'folder') {
                self::createFolder($child, $deployment, $data, $secretData, $portClaims);
            }
        });
    }

    /**
     * Create a file.
     *
     * @param object     $item
     * @param Deployment $deployment
     * @param array      $data
     * @param array      $secretData
     * @param array      $portClaims
     */
    private static function createFile(object $item, Deployment $deployment, array $data = [], array $secretData = [], array $portClaims = [])
    {
        $path = $deployment->path . $item->object->path;

        try {
            $templateContent = Blade::render($item->object->content, [
                'data'   => $data,
                'secret' => $secretData,
                'limits' => [
                    'enabled' => $deployment->limit?->is_active ? 'true' : 'false',
                    'cpu'     => $deployment->limit?->cpu,
                    'memory'  => $deployment->limit?->memory,
                ],
                'portClaims' => $portClaims,
                'paused'     => $deployment->paused,
            ], false);

            Storage::disk('local')->put($path, $templateContent);
        } catch (Exception $e) {
            throw new FluxException('Server Error', 500);
        }
    }
}
