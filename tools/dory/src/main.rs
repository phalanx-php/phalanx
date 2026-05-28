use ripht_php_sapi::{CliRequest, RiphtSapi};
use std::io::Write;
use tempfile::NamedTempFile;

fn main() {
    let php = RiphtSapi::instance();
    php.set_ini("swoole.use_shortname", "Off")
        .expect("Failed to configure swoole.use_shortname");
    php.set_ini("opcache.enable_cli", "1")
        .expect("Failed to configure opcache.enable_cli");
    php.set_ini("memory_limit", "512M")
        .expect("Failed to configure memory_limit");

    let bootstrap_script = include_bytes!("../embedded/bootstrap.php");

    let mut temp_script = NamedTempFile::new().expect("Failed to create temporary script file");
    temp_script
        .write_all(bootstrap_script)
        .expect("Failed to write to temporary script file");

    let req = CliRequest::new()
        .build(temp_script.path())
        .expect("Failed to build CliRequest");

    println!("Booting Dory...");

    let result = php.execute(req);

    match result {
        Ok(exec_result) => {
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
