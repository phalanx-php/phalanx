use std::path::PathBuf;

use ripht_php_sapi::{CliRequest, RiphtSapi, SapiConfig};

fn php_script_path(name: &str) -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests/php_scripts")
        .join(name)
}

#[test]
fn configured_sapi_name_reaches_php() {
    RiphtSapi::configure(SapiConfig::new().sapi_name("cli"))
        .expect("configure failed");

    let php = RiphtSapi::instance();

    let ctx = CliRequest::new()
        .build(&php_script_path("sapi_identity.php"))
        .expect("failed to build request");

    let result = php.execute(ctx).expect("execution failed");
    let body = result.body_string();
    let json: serde_json::Value =
        serde_json::from_str(&body).expect("invalid JSON");

    assert_eq!(json["sapi_name"], "cli");
    assert!(
        json["server_software"]
            .as_str()
            .unwrap()
            .starts_with("Ripht/"),
    );
}
