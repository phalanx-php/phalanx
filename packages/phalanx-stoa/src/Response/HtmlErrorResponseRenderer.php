<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Response;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskTreeFormatter;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * High-fidelity HTML error response implementation.
 * 
 * Renders a modern, branded error page when Stoa is in debug mode
 * and the client accepts HTML. Includes high-fidelity syntax highlighting
 * (Prism.js), active ledger snapshot, and full stack trace.
 */
final readonly class HtmlErrorResponseRenderer implements ErrorResponseRenderer
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
        $file = $e->getFile();
        $line = $e->getLine();
        
        $ledger = '';
        try {
            $ledger = (new TaskTreeFormatter())->format(
                $scope->service(Supervisor::class)->tree(),
            );
        } catch (Cancelled $c) {
            throw $c;
        } catch (Throwable) {
            $ledger = '(Ledger snapshot unavailable)';
        }

        $source = '';
        if (is_file($file)) {
            $source = file_get_contents($file) ?: '';
        }

        $html = $this->template(
            title: $e::class,
            message: $e->getMessage(),
            file: $file,
            line: $line,
            code: $source,
            ledger: $ledger,
            trace: $this->renderTrace($e),
            requestId: ($resource instanceof StoaRequestResource) ? $resource->id : 'unknown'
        );

        return new PsrResponse(
            500,
            ['Content-Type' => 'text/html'],
            $html
        );
    }

    private function renderTrace(Throwable $e): string
    {
        $out = '<ul class="trace-list">';
        $rawTrace = [];
        foreach ($e->getTrace() as $i => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $func = $frame['function'];
            
            $out .= sprintf(
                "<li><span class='frame-num'>%d</span><div class='frame-content'><span class='frame-func'>%s%s%s()</span><span class='frame-loc'>at %s:%d</span></div></li>",
                $i + 1,
                $class,
                $type,
                $func,
                $file,
                $line
            );
            $rawTrace[] = sprintf("%d. %s%s%s() at %s:%d", $i + 1, $class, $type, $func, $file, $line);
        }
        $out .= '</ul>';
        return sprintf(
            "%s<script>window.rawTrace = %s;</script>",
            $out,
            json_encode(implode("\n", $rawTrace))
        );
    }

    private function getLogo(): string
    {
        $path = dirname(__DIR__, 4) . '/logo.svg';
        if (is_file($path)) {
            $svg = file_get_contents($path);
            if ($svg) {
                $svg = preg_replace('#<text.*?</text>#s', '', $svg) ?? $svg;
                $svg = str_replace('viewBox="0 0 520 120"', 'viewBox="0 0 110 120"', $svg);
                return $svg;
            }
        }

        return '';
    }

    private function template(
        string $title,
        string $message,
        string $file,
        int $line,
        string $code,
        string $ledger,
        string $trace,
        string $requestId
    ): string {
        $logo = $this->getLogo();
        $escapedCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        
        $sourceContent = $code !== '' 
            ? "<pre class='line-numbers language-php' data-line='{$line}' id='copy-source-content'><code class='language-php'>{$escapedCode}</code></pre>"
            : "<div class='empty-state'><i data-lucide='file-x' size='48'></i><div>Source code unavailable for this frame</div></div>";

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Error: {$title}</title>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    
    <!-- Prism.js Assets -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-highlight/prism-line-highlight.min.css" rel="stylesheet" />

    <style>
        :root { 
            --red: #ff3b30; 
            --red-bright: #ff453a;
            --red-bg: rgba(255, 59, 48, 0.08); 
            --bg: #09090b; 
            --card-bg: #18181b;
            --border: #27272a;
            --muted: #71717a;
            --text: #fafafa;
            --accent: #38bdf8;
            --mono: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
        }
        
        body { 
            font-family: 'Inter', -apple-system, system-ui, sans-serif; 
            background: var(--bg); 
            color: var(--text); 
            line-height: 1.6; 
            margin: 0; 
            padding: 0; 
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        
        .container { width: 100%; max-width: 1200px; padding: 3rem 2rem; margin: 0 auto; box-sizing: border-box; }
        
        .nav-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 3rem; }
        .logo-wrap { height: 32px; }
        .logo-wrap svg { height: 100%; width: auto; }
        .runtime-badge { background: var(--card-bg); border: 1px solid var(--border); padding: 0.3rem 0.8rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; color: var(--muted); letter-spacing: 0.05em; }

        .error-card { 
            background: var(--card-bg); 
            border: 1px solid var(--border); 
            border-radius: 12px; 
            overflow: hidden; 
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            margin-bottom: 2rem;
        }
        
        .error-header { 
            padding: 2.5rem; 
            background: linear-gradient(to bottom right, var(--red-bg), transparent); 
            border-bottom: 1px solid var(--border);
        }
        
        .error-type { 
            color: var(--red-bright); 
            font-size: 0.75rem; 
            text-transform: uppercase; 
            letter-spacing: 0.2em; 
            font-weight: 800; 
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .error-message { font-size: 2rem; font-weight: 800; color: #fff; line-height: 1.2; letter-spacing: -0.02em; }
        .error-loc { font-family: var(--mono); color: var(--muted); margin-top: 1.5rem; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; }

        .tab-nav { 
            display: flex; 
            background: #09090b; 
            padding: 0.5rem; 
            gap: 0.25rem;
            border-bottom: 1px solid var(--border);
        }
        
        .tab-btn { 
            background: transparent; 
            border: none; 
            color: var(--muted); 
            padding: 0.6rem 1.2rem; 
            cursor: pointer; 
            font-size: 0.85rem; 
            font-weight: 600; 
            border-radius: 6px;
            display: flex; 
            align-items: center; 
            gap: 0.6rem; 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        
        .tab-btn:hover { background: #18181b; color: var(--text); }
        .tab-btn.active { background: #27272a; color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        
        .tab-pane { display: none; padding: 0; min-height: 500px; animation: fadeIn 0.2s ease-out; }
        .tab-pane.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

        .pane-toolbar { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 1rem 1.5rem;
            background: #111113;
            border-bottom: 1px solid var(--border);
        }
        
        .pane-title { font-size: 0.7rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        
        .copy-btn { 
            background: transparent; 
            border: 1px solid var(--border); 
            color: var(--muted); 
            padding: 0.35rem 0.75rem; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 0.75rem; 
            font-weight: 600;
            display: flex; 
            align-items: center; 
            gap: 0.4rem; 
            transition: all 0.2s;
        }
        .copy-btn:hover { background: var(--border); color: var(--text); border-color: var(--muted); }

        pre { margin: 0 !important; padding: 1.5rem !important; overflow-x: auto; font-family: var(--mono) !important; font-size: 0.85rem !important; color: #d1d1d6; line-height: 1.7; }
        
        /* Prism Overrides */
        pre[class*="language-"] { background: #000 !important; border: none !important; margin: 0 !important; border-radius: 0 !important; }
        .line-numbers .line-numbers-rows { border-right: 1px solid #18181b !important; padding-top: 1.5rem !important; left: 0 !important; }
        .line-highlight { background: rgba(255, 59, 48, 0.15) !important; border-left: 2px solid var(--red) !important; }

        ul.trace-list { list-style: none; padding: 0; margin: 0; }
        .trace-list li { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); font-family: var(--mono); font-size: 0.85rem; display: flex; align-items: flex-start; gap: 1rem; }
        .trace-list li:last-child { border-bottom: none; }
        .trace-list li:hover { background: #1c1c1f; }
        .frame-num { color: var(--muted); flex-shrink: 0; width: 20px; }
        .frame-content { display: flex; flex-direction: column; }
        .frame-func { color: #e4e4e7; font-weight: 600; }
        .frame-loc { color: var(--muted); font-size: 0.75rem; margin-top: 0.25rem; }
        
        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 500px; color: var(--muted); gap: 1rem; }
        
        footer { margin-top: 4rem; padding-bottom: 4rem; color: var(--muted); font-size: 0.75rem; text-align: center; }
        .footer-info { display: flex; align-items: center; justify-content: center; gap: 1.5rem; }
        .req-id { font-family: var(--mono); background: var(--card-bg); padding: 0.2rem 0.5rem; border-radius: 4px; border: 1px solid var(--border); }
    </style>
</head>
<body class="line-numbers">
    <div class="container">
        <div class="nav-header">
            <div class="logo-wrap">{$logo}</div>
            <div class="runtime-badge">PHALANX 0.2 / OPEN SWOOLE 26</div>
        </div>

        <div class="error-card">
            <div class="error-header">
                <div class="error-type">
                    <i data-lucide="alert-circle" size="14"></i>
                    {$title}
                </div>
                <div class="error-message">{$message}</div>
                <div class="error-loc">
                    <i data-lucide="file-text" size="14"></i>
                    {$file}:{$line}
                </div>
            </div>

            <div class="tab-nav">
                <button class="tab-btn active" onclick="switchTab('source', this)">
                    <i data-lucide="code" size="14"></i> Source
                </button>
                <button class="tab-btn" onclick="switchTab('ledger', this)">
                    <i data-lucide="layers" size="14"></i> Active Ledger
                </button>
                <button class="tab-btn" onclick="switchTab('trace', this)">
                    <i data-lucide="list" size="14"></i> Stack Trace
                </button>
            </div>

            <div class="tab-content">
                <!-- Source Tab -->
                <div id="tab-source" class="tab-pane active">
                    <div class="pane-toolbar">
                        <div class="pane-title">Failing Logic</div>
                        <button class="copy-btn" onclick="copyToClipboard('source', this)">
                            <i data-lucide="copy" size="12"></i> Copy
                        </button>
                    </div>
                    {$sourceContent}
                </div>

                <!-- Ledger Tab -->
                <div id="tab-ledger" class="tab-pane">
                    <div class="pane-toolbar">
                        <div class="pane-title">Concurrency Snapshot</div>
                        <button class="copy-btn" onclick="copyToClipboard('ledger', this)">
                            <i data-lucide="copy" size="12"></i> Copy
                        </button>
                    </div>
                    <pre id="copy-ledger-content">{$ledger}</pre>
                </div>

                <!-- Trace Tab -->
                <div id="tab-trace" class="tab-pane">
                    <div class="pane-toolbar">
                        <div class="pane-title">Execution Path</div>
                        <button class="copy-btn" onclick="copyToClipboard('trace', this)">
                            <i data-lucide="copy" size="12"></i> Copy
                        </button>
                    </div>
                    <div class="trace-container">
                        {$trace}
                    </div>
                </div>
            </div>
        </div>

        <footer>
            <div class="footer-info">
                <span>PHALANX COORDINATION ENGINE</span>
                <span class="req-id">RID: {$requestId}</span>
            </div>
        </footer>
    </div>

    <!-- Prism JS Bundle -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-highlight/prism-line-highlight.min.js"></script>

    <script>
        lucide.createIcons();

        function switchTab(tabId, btn) {
            console.log('Switching to tab: ' + tabId);
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            
            document.getElementById('tab-' + tabId).classList.add('active');
            btn.classList.add('active');
            
            if (tabId === 'source' && typeof Prism !== 'undefined') {
                console.log('Triggering Prism highlight');
                Prism.highlightAll();
            }
        }

        async function copyToClipboard(tabId, btn) {
            const contentEl = document.getElementById('copy-' + tabId + '-content');
            if (!contentEl && tabId !== 'trace') return;

            let content = '';
            if (tabId === 'source' || tabId === 'ledger') {
                content = contentEl.textContent;
            } else if (tabId === 'trace') {
                content = window.rawTrace;
            }

            try {
                await navigator.clipboard.writeText(content);
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i data-lucide="check" size="12"></i> Copied';
                btn.style.color = 'var(--red-bright)';
                lucide.createIcons();
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.style.color = '';
                    lucide.createIcons();
                }, 2000);
            } catch (err) {
                console.error('Failed to copy: ', err);
            }
        }
        
        window.addEventListener('load', () => {
            console.log('Page loaded');
            if (typeof Prism !== 'undefined') {
                console.log('Prism found, highlighting...');
                Prism.highlightAll();
            }
            setTimeout(() => {
                const highlight = document.querySelector('.line-highlight');
                if (highlight) {
                    highlight.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 500);
        });
    </script>
</body>
</html>
HTML;
    }
}
