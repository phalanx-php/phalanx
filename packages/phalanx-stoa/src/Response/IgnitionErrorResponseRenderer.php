<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use Closure;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Response\Ignition\PhalanxErrorPageViewModel;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Supervisor\TaskTreeFormatter;
use Psr\Http\Message\ResponseInterface;
use Spatie\Ignition\Config\IgnitionConfig;
use Spatie\Ignition\ErrorPage\Renderer;
use Spatie\Ignition\Ignition;
use Spatie\Ignition\Solutions\SolutionTransformer;
use Throwable;

/**
 * World-class Ignition error response implementation.
 * 
 * Renders the Phalanx-flavored Ignition splash page when Stoa is in debug mode
 * and the client accepts HTML. Integrates the Phalanx Active Ledger
 * as a native-feeling UI component.
 */
final readonly class IgnitionErrorResponseRenderer implements ErrorResponseRenderer
{
    public function __construct(private bool $debug = false)
    {
    }

    public function render(RequestScope $scope, Throwable $e): ?ResponseInterface
    {
        if (!$this->debug || !$scope->acceptsHtml()) {
            return null;
        }

        $resource = $scope->attribute(StoaScopeKey::RequestResource->value);
        $requestId = ($resource instanceof StoaRequestResource) ? $resource->id : 'unknown';
        
        // 1. Capture Active Ledger snapshot from scope (preserved in StoaRunner)
        $snapshots = $scope->attribute('phx.error_ledger', []);
        $ledger = '(no active tasks captured)';
        if ($snapshots !== []) {
            try {
                $ledger = (new TaskTreeFormatter())->format($snapshots);
            } catch (Throwable) {
                $ledger = '(error formatting ledger)';
            }
        }

        // 2. Prepare the customized View Model
        try {
            $ignition = new Ignition();
            
            // handleException is the public way to get a Report from Ignition
            $report = $ignition->handleException($e);
            
            // Add Phalanx Context
            $report->context('Phalanx', [
                'Request ID' => $requestId,
                'Method' => $scope->method(),
                'Path' => $scope->path(),
            ]);

            $report->context('Active Ledger', [
                'Fiber Hierarchy' => $ledger,
            ]);

            $viewModel = new PhalanxErrorPageViewModel(
                $e,
                IgnitionConfig::loadFromConfigFile(),
                $report,
                [], // solutions
                SolutionTransformer::class,
                $this->getCustomHead(),
                $this->getCustomBody($ledger)
            );

            // 3. Render using our local assets
            $renderer = new Renderer();
            // packages/phalanx-stoa/src/Response/IgnitionErrorResponseRenderer.php
            // dirname(__DIR__, 2) => packages/phalanx-stoa
            $viewPath = dirname(__DIR__, 2) . '/resources/ignition/views/errorPage.php';
            
            if (!is_file($viewPath)) {
                return null;
            }

            $html = $renderer->renderAsString(['viewModel' => $viewModel], $viewPath);
            
            if ($html === '') {
                return null;
            }
            
            return new \GuzzleHttp\Psr7\Response(
                500,
                ['Content-Type' => 'text/html', 'X-Phalanx-Renderer' => 'Ignition'],
                $html
            );
        } catch (Throwable) {
            return null; 
        }
    }

    private function getCustomHead(): string
    {
        return <<<HTML
        <!-- Prism.js for High-Fidelity Syntax Highlighting -->
        <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" />
        <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet" />
        <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-highlight/prism-line-highlight.min.css" rel="stylesheet" />
        <style>
            .phx-logo-container { position: fixed; top: 1rem; right: 2rem; z-index: 9999; display: flex; align-items: center; gap: 1rem; pointer-events: none; }
            .phx-logo-wrap { height: 28px; opacity: 0.9; }
            .phx-logo-wrap svg { height: 100%; width: auto; }
            .phx-badge { background: rgba(24, 24, 27, 0.9); border: 1px solid rgba(39, 39, 42, 0.8); padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.65rem; color: #f2f2f7; font-weight: 800; backdrop-filter: blur(8px); letter-spacing: 0.05em; }
            
            #phx-ledger-panel { 
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(9, 9, 11, 0.98); z-index: 10000; 
                display: none; padding: 4rem; box-sizing: border-box;
                overflow-y: auto;
            }
            #phx-ledger-panel.active { display: block; }
            .phx-ledger-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; border-bottom: 1px solid #27272a; padding-bottom: 1rem; }
            .phx-ledger-title { font-size: 1.5rem; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
            .phx-close-btn { background: #27272a; color: #fafafa; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; font-weight: 600; }
            .phx-ledger-content { font-family: 'JetBrains Mono', monospace; font-size: 0.9rem; color: #d1d1d6; line-height: 1.7; white-space: pre; background: #000; padding: 2.5rem; border-radius: 8px; border: 1px solid #18181b; }
            
            /* High-fidelity code tweaks */
            pre[class*="language-"] { background: #000 !important; border: none !important; border-radius: 0 !important; }
            .line-highlight { background: rgba(255, 59, 48, 0.15) !important; border-left: 2px solid #ff3b30 !important; }
        </style>
HTML;
    }

    private function getCustomBody(string $ledger): string
    {
        $logo = $this->getLogo();
        $escapedLedger = htmlspecialchars($ledger);

        return <<<HTML
        <div class='phx-logo-container'>
            <div class='phx-logo-wrap'>{$logo}</div>
            <div class='phx-badge'>PHALANX 0.2</div>
        </div>

        <div id="phx-ledger-panel">
            <div class="phx-ledger-header">
                <div class="phx-ledger-title">Active Ledger Snapshot</div>
                <button class="phx-close-btn" onclick="document.getElementById('phx-ledger-panel').classList.remove('active')">Close</button>
            </div>
            <div class="phx-ledger-content">{$escapedLedger}</div>
        </div>

        <!-- Prism JS for High-Fidelity highlighting -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-highlight/prism-line-highlight.min.js"></script>

        <script>
            window.addEventListener('load', () => {
                let pollerCount = 0;
                const interval = setInterval(() => {
                    pollerCount++;
                    const nav = document.querySelector('nav ul');
                    const footer = document.querySelector('footer p');
                    
                    if (nav || footer || pollerCount > 100) {
                        // 1. Ledger Trigger
                        if (nav && !document.getElementById('phx-ledger-trigger')) {
                             const li = document.createElement('li');
                             li.id = 'phx-ledger-trigger';
                             li.className = 'grid grid-flow-col justify-start items-center cursor-pointer px-4 text-gray-500 hover:text-red-500 transition-colors';
                             li.innerHTML = '<span class="text-xs font-bold uppercase tracking-wider">Ledger</span>';
                             li.onclick = (e) => {
                                e.preventDefault();
                                document.getElementById('phx-ledger-panel').classList.add('active');
                             };
                             nav.appendChild(li);
                        }

                        // 2. Re-highlight code blocks
                        if (typeof Prism !== 'undefined') {
                            document.querySelectorAll('pre code').forEach((block) => {
                                if (!block.classList.contains('language-php')) {
                                    block.classList.add('language-php');
                                    Prism.highlightElement(block);
                                }
                            });
                        }

                        // 3. Scrub branding
                        if (footer) {
                            footer.innerHTML = 'Diagnostics powered by Phalanx 0.2';
                        }

                        document.querySelectorAll('nav ul li a').forEach(a => {
                            if (a.href.includes('flareapp.io') || a.href.includes('laravel.com')) {
                                a.closest('li').style.display = 'none';
                            }
                        });
                        
                        clearInterval(interval);
                    }
                }, 200);
            });
        </script>
HTML;
    }

    private function getLogo(): string
    {
        // packages/phalanx-stoa/src/Response/IgnitionErrorResponseRenderer.php
        $path = dirname(__DIR__, 4) . '/logo.svg';
        if (is_file($path)) {
            $svg = file_get_contents($path);
            if ($svg) {
                $svg = preg_replace('#<text.*?</text>#s', '', $svg);
                return str_replace('viewBox="0 0 520 120"', 'viewBox="0 0 110 120"', $svg);
            }
        }
        return '';
    }
}
