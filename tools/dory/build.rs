use std::env;
use std::fs;
use std::path::Path;

fn main() {
    let prefix = format!("{}/.ripht/php/lib", env::var("HOME").unwrap());
    
    // Tell Cargo where to find the static libraries built by SPC
    println!("cargo:rustc-link-search=native={prefix}");

    // The core PHP library must be linked
    println!("cargo:rustc-link-lib=static=php");

    // Dynamically link all other static libraries produced by SPC
    let lib_dir = Path::new(&prefix);
    if lib_dir.exists() && lib_dir.is_dir() {
        for entry in fs::read_dir(lib_dir).expect("Failed to read lib directory") {
            let entry = entry.expect("Failed to read directory entry");
            let path = entry.path();
            if path.is_file() {
                if let Some(extension) = path.extension() {
                    if extension == "a" {
                        if let Some(file_name) = path.file_stem().and_then(|n| n.to_str()) {
                            if file_name.starts_with("lib") && file_name != "libphp" {
                                let lib_name = &file_name[3..];
                                println!("cargo:rustc-link-lib=static={}", lib_name);
                            }
                        }
                    }
                }
            }
        }
    }

    // macOS frameworks required by Swoole and other extensions
    println!("cargo:rustc-link-lib=framework=CoreServices");
    println!("cargo:rustc-link-lib=framework=CoreFoundation");
    println!("cargo:rustc-link-lib=framework=SystemConfiguration");
    println!("cargo:rustc-link-lib=framework=Security");
    println!("cargo:rustc-link-lib=resolv"); // Commonly needed by DNS/networking
    
    // Re-run this build script if it changes
    println!("cargo:rerun-if-changed=build.rs");
}
