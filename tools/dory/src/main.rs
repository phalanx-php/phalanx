use ripht_php_sapi::{CliRequest, RiphtSapi, SapiConfig};
use std::io::Write;
use tempfile::NamedTempFile;

fn main() {
    // Spoof SAPI name to "cli" for Swoole initialization
    RiphtSapi::configure(
        SapiConfig::new()
            .sapi_name("cli")
            .ini_entry("swoole.use_shortname", "Off")
            .ini_entry("opcache.enable_cli", "1")
            .ini_entry("memory_limit", "512M")
    )
        .expect("Failed to configure RiphtSapi");

    let php = RiphtSapi::instance();

    // The embedded bootstrap.php script that will load Aegis and setup the environment
    let bootstrap_script = include_bytes!("../embedded/bootstrap.php");
    
    // Write the embedded script to a temporary file since RiphtSapi executes files
    let mut temp_script = NamedTempFile::new().expect("Failed to create temporary script file");
    temp_script.write_all(bootstrap_script).expect("Failed to write to temporary script file");

    let req = CliRequest::new()
        .build(temp_script.path())
        .expect("Failed to build CliRequest");

    println!("Booting Dory...");
    
    let result = php.execute(req);

    match result {
        Ok(exec_result) => {
            // Print the captured output
            print!("{}", exec_result.body_string());
            
            if !exec_result.is_success() {
                std::process::exit(1);
            }
        }
        Err(e) => {
            eprintln!("Failed to execute Dory bootstrap: {}", e);
            std::process::exit(1);
        }
    }
}
