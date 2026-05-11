<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Response\Ignition\PhalanxErrorPageViewModel;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Stoa\StoaServerConfig;
use Phalanx\Supervisor\TaskTreeFormatter;
use Psr\Http\Message\ResponseInterface;
use Spatie\Ignition\Config\IgnitionConfig;
use Spatie\Ignition\ErrorPage\Renderer;
use Spatie\Ignition\Ignition;
use Spatie\Ignition\Solutions\SolutionTransformer;
use Throwable;

final readonly class IgnitionErrorResponseRenderer implements ErrorResponseRenderer
{
    public function __construct(private StoaServerConfig $config = new StoaServerConfig())
    {
    }

    public function render(RequestScope $scope, Throwable $e): ?ResponseInterface
    {
        if (!$this->config->ignitionEnabled || !$scope->acceptsHtml()) {
            return null;
        }

        try {
            $resource = $scope->attribute(StoaScopeKey::RequestResource->value);
            $requestId = ($resource instanceof StoaRequestResource) ? $resource->id : 'unknown';
            
            $snapshots = $scope->attribute('phx.error_ledger', []);
            $ledger = '(no active tasks captured)';
            if ($snapshots !== []) {
                try {
                    $ledger = (new TaskTreeFormatter())->format($snapshots);
                } catch (Cancelled $c) {
                    throw $c;
                } catch (Throwable) {
                    $ledger = '(error formatting ledger)';
                }
            }

            $ignition = new Ignition();
            $report = $ignition->handleException($e);
            
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
                [], 
                SolutionTransformer::class,
                $this->getCustomHead(),
                $this->getCustomBody($ledger)
            );

            $renderer = new Renderer();
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
        } catch (Cancelled $c) {
            throw $c;
        } catch (Throwable $renderError) {
            // Extreme resilience: fallback to other renderers on any crash
            fwrite(STDERR, "PHALANX DEBUG: Ignition Renderer failed: " . $renderError->getMessage() . "\n");
            return null;
        }
    }

    private function getCustomHead(): string
    {
        $favicon = $this->config->faviconPath;
        
        return <<<HTML
        <!-- Substrate-Native Branding & Favicon -->
        <link rel="icon" type="image/x-icon" href="{$favicon}">
        
        <!-- Prism.js for High-Fidelity Syntax Highlighting -->
        <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" />
        <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet" />
        <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-highlight/prism-line-highlight.min.css" rel="stylesheet" />
        <style>
            /* 1. Navbar Alignment & Branding */
            .phx-nav-branding { display: flex; align-items: center; gap: 0.75rem; padding-right: 1.5rem; margin-right: 0.5rem; border-right: 1px solid rgba(255,255,255,0.1); }
            .phx-logo-wrap { display: flex; align-items: center; height: 18px; }
            .phx-logo-wrap svg { height: 100%; width: auto; }
            .phx-badge { background: #18181b; border: 1px solid #27272a; padding: 0.1rem 0.4rem; border-radius: 4px; font-size: 0.6rem; color: #a1a1aa; font-weight: 700; letter-spacing: 0.05em; line-height: 1.2; }
            
            /* 2. NUCLEAR SHARP UI: Eliminate ALL blurs, shadows, and masks */
            * { box-shadow: none !important; filter: none !important; -webkit-filter: none !important; text-shadow: none !important; backdrop-filter: none !important; }
            .mask-fade-r, .mask-fade-l, [class*="mask-fade"] { -webkit-mask-image: none !important; mask-image: none !important; }
            pre[class*="language-"], pre.sf-dump, .ignition-code-snippet, main div[class*="bg-"] { background: #09090b !important; border: 1px solid #18181b !important; border-radius: 4px !important; }

            /* 3. Ledger Panel - Full Screen 'Elite' UI */
            #phx-ledger-panel { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: #000; z-index: 10000; display: none; flex-direction: column; box-sizing: border-box; }
            #phx-ledger-panel.active { display: flex; }
            .phx-ledger-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 2.5rem; border-bottom: 1px solid #18181b; background: #09090b; }
            .phx-ledger-title { font-size: 0.9rem; font-weight: 800; color: #fff; letter-spacing: 0.1em; display: flex; align-items: center; gap: 0.75rem; }
            .phx-ledger-title::before { content: ''; display: block; width: 3px; height: 1.2rem; background: #ef4444; }
            .phx-close-btn { background: #27272a; color: #fff; border: 1px solid #3f3f46; padding: 0.4rem 1rem; border-radius: 4px; cursor: pointer; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
            .phx-close-btn:hover { background: #ef4444; border-color: #ef4444; }
            .phx-ledger-body { flex: 1; overflow: auto; padding: 2rem; background: #000; }
            .phx-ledger-content { font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; color: #d1d1d6; line-height: 1.6; white-space: pre; background: #09090b; padding: 2rem; border: 1px solid #27272a; min-width: min-content; }
            
            /* High-fidelity code tweaks */
            pre[class*="language-"] { background: #000 !important; border: none !important; border-radius: 0 !important; padding: 1.5rem !important; }
            .line-highlight { background: rgba(255, 59, 48, 0.2) !important; border-left: 2px solid #ff3b30 !important; }
            
            /* Documentation Link Cluster */
            .phx-doc-cluster { display: flex; align-items: center; gap: 0.75rem; }
            .phx-doc-item { display: flex; align-items: center; gap: 0.4rem; color: #71717a; text-decoration: none; font-size: 0.7rem; font-weight: 700; transition: color 0.15s; text-transform: uppercase; letter-spacing: 0.02em; }
            .phx-doc-item:hover { color: #fafafa; }
            .phx-doc-icon { height: 12px; width: auto; opacity: 0.5; filter: grayscale(1); transition: opacity 0.15s, filter 0.15s; }
            .phx-doc-item:hover .phx-doc-icon { opacity: 1; filter: grayscale(0); }

            /* Footer Branding Fixes */
            footer { border-top: 1px solid #18181b !important; padding: 3rem 0 !important; }
            .phx-footer-wrap { display: flex; align-items: center; gap: 2rem; width: 100%; }
            .phx-footer-logo { height: 28px; width: auto; opacity: 0.8; }
            .phx-footer-logo svg { height: 100%; width: auto; }
        </style>
HTML;
    }

    private function getCustomBody(string $ledger): string
    {
        $logo = $this->getLogo();
        $escapedLedger = htmlspecialchars($ledger);
        $docsUrl = $this->config->docsUrl;
        $osDocsUrl = $this->config->openswooleDocsUrl;
        $githubUrl = $this->config->githubUrl;
        $tagline = $this->config->tagline;

        return <<<HTML
        <div id="phx-ledger-panel">
            <div class="phx-ledger-header">
                <div class="phx-ledger-title">PHALANX ACTIVE LEDGER</div>
                <button class="phx-close-btn" onclick="document.getElementById('phx-ledger-panel').classList.remove('active')">Close Snapshot</button>
            </div>
            <div class="phx-ledger-body">
                <div class="phx-ledger-content">{$escapedLedger}</div>
            </div>
        </div>

        <script>
            document.documentElement.classList.remove('light', 'auto');
            document.documentElement.classList.add('dark');

            window.addEventListener('load', () => {
                let pollerCount = 0;
                const interval = setInterval(() => {
                    pollerCount++;
                    const navs = document.querySelectorAll('nav ul');
                    const navLeft = navs[0];
                    const navRight = navs[1];
                    const footer = document.querySelector('footer p');
                    const footerContainer = document.querySelector('footer');
                    
                    if (navLeft || footer || pollerCount > 100) {
                        if (navLeft && !document.getElementById('phx-nav-branding')) {
                             const branding = document.createElement('li');
                             branding.id = 'phx-nav-branding';
                             branding.className = 'phx-nav-branding';
                             branding.innerHTML = `
                                <div class="phx-logo-wrap">{$logo}</div>
                                <div class="phx-badge">PHALANX 0.2</div>
                             `;
                             navLeft.prepend(branding);

                             const ledgerItem = document.createElement('li');
                             ledgerItem.id = 'phx-ledger-trigger';
                             ledgerItem.className = 'grid grid-flow-col justify-start items-center cursor-pointer px-4 text-gray-500 hover:text-red-500 transition-colors';
                             ledgerItem.innerHTML = '<span class="text-xs font-bold uppercase tracking-wider">Ledger</span>';
                             ledgerItem.onclick = (e) => {
                                e.preventDefault();
                                document.getElementById('phx-ledger-panel').classList.add('active');
                             };
                             navLeft.appendChild(ledgerItem);
                        }

                        if (navRight && !document.getElementById('phx-doc-cluster')) {
                            const cluster = document.createElement('li');
                            cluster.id = 'phx-doc-cluster';
                            cluster.className = 'px-4';
                            cluster.innerHTML = `
                                <div class="phx-doc-cluster">
                                    <a href="https://php.net/docs" target="_blank" class="phx-doc-item">
                                        <img src="https://www.php.net/images/logos/php-logo-white.svg" class="phx-doc-icon" style="height:10px">
                                        <span>PHP</span>
                                    </a>
                                    <a href="{$osDocsUrl}" target="_blank" class="phx-doc-item">
                                        <img src="https://openswoole.com/images/swoole-logo-white.svg" class="phx-doc-icon">
                                        <span>OpenSwoole</span>
                                    </a>
                                    <a href="{$githubUrl}" target="_blank" class="phx-doc-item">
                                        <img src="https://raw.githubusercontent.com/phalanx-php/phalanx/refs/heads/main/mark.png" class="phx-doc-icon">
                                        <span>Phalanx</span>
                                    </a>
                                </div>
                            `;
                            navRight.prepend(cluster);
                        }

                        if (footer) {
                            footer.innerHTML = `
                                <div class="phx-footer-wrap">
                                    <div class="phx-footer-logo">{$logo}</div>
                                    <div>
                                        <p style="font-weight:800; font-size:0.85rem; color:#fafafa; margin:0; letter-spacing:0.05em; text-transform:uppercase">PHALANX SUBSTRATE 0.2</p>
                                        <p style="font-size:0.7rem; color:#71717a; margin:0.25rem 0 0.75rem 0; font-weight:500">{$tagline}</p>
                                        <a href="{$docsUrl}" target="_blank" style="font-size:0.65rem; font-weight:800; color:#ef4444; text-decoration:none; text-transform:uppercase; letter-spacing:0.1em">View Documentation &rarr;</a>
                                    </div>
                                </div>
                            `;
                        }

                        document.querySelectorAll('nav ul li a').forEach(a => {
                            if (a.href.includes('flareapp.io') || a.href.includes('laravel.com')) {
                                const li = a.closest('li');
                                if (li) li.style.display = 'none';
                            }
                        });
                        
                        if (footerContainer) {
                             const footerP = footerContainer.querySelector('div p');
                             if (footerP && footerP.innerHTML.includes('Ignition is built')) {
                                 footerP.style.display = 'none';
                             }
                             const footerLinks = footerContainer.querySelector('ul');
                             if (footerLinks) {
                                  footerLinks.querySelectorAll('li a').forEach(a => {
                                      if (a.href.includes('laravel.com')) a.closest('li').style.display = 'none';
                                  });
                             }
                        }
                        
                        clearInterval(interval);
                    }
                }, 100);
            });
        </script>
HTML;
    }

    private function getLogo(): string
    {
        $path = dirname(__DIR__, 5) . $this->config->logoPath;
        if (is_file($path)) {
            $svg = file_get_contents($path);
            if ($svg) {
                $svg = preg_replace('#<text.*?</text>#s', '', $svg) ?? $svg;
                return str_replace('viewBox="0 0 520 120"', 'viewBox="0 0 110 120"', $svg);
            }
        }
        return '';
    }
}
