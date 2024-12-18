<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2024 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\Http\Listener;

use Glpi\Http\LegacyRouterTrait;
use Glpi\Http\ListenersPriority;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;

final class LegacyAssetsListener implements EventSubscriberInterface
{
    use LegacyRouterTrait;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $glpi_root,
        array $plugin_directories = PLUGINS_DIRECTORIES,
    ) {
        $this->glpi_root = $glpi_root;
        $this->plugin_directories = $plugin_directories;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', ListenersPriority::LEGACY_LISTENERS_PRIORITIES[self::class]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (
            $request->attributes->get('_controller') !== null
            || $event->getResponse() !== null
        ) {
            // A controller or a response has already been defined for this request, do not override them.
            return;
        }

        $response = $this->serveLegacyAssets($request);

        if ($response) {
            $event->setResponse($response);
        }
    }

    private function serveLegacyAssets(Request $request): ?Response
    {
        [$uri_prefix, $path] = $this->extractPathAndPrefix($request);

        $target_file = $this->getTargetFile($path);

        if (
            $target_file === null
            || $this->isTargetAPhpScript($path)
            || $this->isHiddenFile($path)
        ) {
            return null;
        }

        // Serve static files if web server is not configured to do it directly.
        $response = new BinaryFileResponse($target_file, 200, [
            'Content-Type' => $this->getMimeType($target_file),
        ]);
        $response->setMaxAge(2_592_000);
        $response->mustRevalidate();

        // Automatically modifies the response to 304 if HTTP Cache headers match
        $response->isNotModified($request);

        return $response;
    }

    private function getMimeType(string $target_file): string
    {
        $extension = \pathinfo($target_file, PATHINFO_EXTENSION);

        switch ($extension) {
            case 'css':
                return 'text/css';
            case 'js':
            case 'vue':
                return 'application/javascript';
            case 'woff':
                return 'font/woff';
            case 'woff2':
                return 'font/woff2';
            default:
                $mime = \mime_content_type($target_file);

                if ($mime === false) {
                    $mime = 'application/octet-stream';
                }

                return $mime;
        }
    }
}