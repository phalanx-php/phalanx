use std::env;
use std::path::{Path, PathBuf};
use std::process::ExitCode;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;

use ripht_php_sapi::{
    CliRequest, ExecutionHooks, ExecutionMessage, ExecutionResult, OutputAction, RiphtSapi,
    SapiConfig,
};

fn main() -> ExitCode {
    let args: Vec<String> = env::args().collect();

    if args.len() < 2 {
        eprintln!("usage: dory-poc <script.php> [--hooks]");
        eprintln!();
        eprintln!("levels:");
        eprintln!("  scripts/level0-sapi-init.php      Extension loads, SAPI name check");
        eprintln!("  scripts/level1-coroutines.php      Co::create, Co::sleep, interleaving");
        eprintln!("  scripts/level2-runtime-hooks.php   Runtime::enableCoroutine, hooked I/O");
        eprintln!("  scripts/level3-channel-wg.php      Channel + WaitGroup coordination");
        eprintln!("  scripts/level4-http-server.php     HTTP server in SIMPLE_MODE");
        eprintln!("  scripts/level5-aegis-scope.php     Aegis scope + task execution");
        return ExitCode::from(1);
    }

    let script = &args[1];
    let use_hooks = args.iter().any(|a| a == "--hooks");

    let scripts_dir = Path::new(env!("CARGO_MANIFEST_DIR")).join("scripts");
    let script_path = resolve_script(script, &scripts_dir);

    RiphtSapi::configure(SapiConfig::new().sapi_name("cli"))
        .expect("SAPI configuration failed");
    let php = RiphtSapi::instance();

    let shutdown = Arc::new(AtomicBool::new(false));
    signal_hook::flag::register(signal_hook::consts::SIGINT, Arc::clone(&shutdown)).ok();
    signal_hook::flag::register(signal_hook::consts::SIGTERM, Arc::clone(&shutdown)).ok();

    let ctx = CliRequest::new()
        .with_env("PHALANX_POC", "1")
        .build(&script_path)
        .unwrap_or_else(|e| {
            eprintln!("failed to build context: {e}");
            std::process::exit(1);
        });

    let result = if use_hooks {
        php.execute_with_hooks(ctx, PocHooks::new(Arc::clone(&shutdown)))
    } else {
        php.execute_streaming(ctx, |data| {
            print!("{}", String::from_utf8_lossy(data));
        })
    };

    match result {
        Ok(result) => {
            print_messages(&result);

            if result.is_success() || result.status_code() == 0 {
                ExitCode::SUCCESS
            } else {
                eprintln!("[poc] exit status: {}", result.status_code());
                ExitCode::from(1)
            }
        }
        Err(e) => {
            eprintln!("[poc] execution error: {e}");
            ExitCode::from(2)
        }
    }
}

fn resolve_script(input: &str, scripts_dir: &Path) -> PathBuf {
    let direct = PathBuf::from(input);
    if direct.exists() {
        return std::fs::canonicalize(&direct).unwrap_or(direct);
    }

    let in_scripts = scripts_dir.join(input);
    if in_scripts.exists() {
        return std::fs::canonicalize(&in_scripts).unwrap_or(in_scripts);
    }

    let with_ext = scripts_dir.join(format!("{input}.php"));
    if with_ext.exists() {
        return std::fs::canonicalize(&with_ext).unwrap_or(with_ext);
    }

    direct
}

fn print_messages(result: &ExecutionResult) {
    for msg in result.all_messages() {
        let prefix = if msg.is_error() {
            "ERROR"
        } else if msg.is_warning() {
            "WARN"
        } else {
            "INFO"
        };
        eprintln!("[php:{prefix}] {}", msg.message);
    }
}

struct PocHooks {
    output_bytes: usize,
    shutdown: Arc<AtomicBool>,
}

impl PocHooks {
    fn new(shutdown: Arc<AtomicBool>) -> Self {
        Self {
            output_bytes: 0,
            shutdown,
        }
    }
}

impl ExecutionHooks for PocHooks {
    fn on_request_started(&mut self) {
        eprintln!("[rust] PHP request started");
    }

    fn on_script_executing(&mut self, script_path: &Path) {
        eprintln!("[rust] executing: {}", script_path.display());
    }

    fn on_output(&mut self, data: &[u8]) -> OutputAction {
        self.output_bytes += data.len();
        print!("{}", String::from_utf8_lossy(data));
        OutputAction::Done
    }

    fn on_php_message(&mut self, message: &ExecutionMessage) {
        eprintln!("[rust:{}] {}", message.level, message.message);
    }

    fn on_script_executed(&mut self, success: bool) {
        eprintln!(
            "[rust] script finished: success={success}, output={} bytes",
            self.output_bytes
        );
    }

    fn is_connection_alive(&self) -> bool {
        !self.shutdown.load(Ordering::Relaxed)
    }

    fn on_request_finished(&mut self, result: &ExecutionResult) {
        eprintln!("[rust] request done: status={}", result.status_code());
    }
}
