fn main() {
    let prefix = format!("{}/.ripht/php/lib", std::env::var("HOME").unwrap());
    println!("cargo:rustc-link-search=native={prefix}");

    // Swoole deps baked into libphp.a but not linked by ripht's build.rs
    println!("cargo:rustc-link-lib=static=cares");
    println!("cargo:rustc-link-lib=static=nghttp2");
    println!("cargo:rustc-link-lib=static=brotlidec");
    println!("cargo:rustc-link-lib=static=brotlienc");
    println!("cargo:rustc-link-lib=static=brotlicommon");
    println!("cargo:rustc-link-lib=static=ssl");
    println!("cargo:rustc-link-lib=static=crypto");
    println!("cargo:rustc-link-lib=static=curl");
    println!("cargo:rustc-link-lib=static=charset");

    // macOS frameworks swoole needs
    println!("cargo:rustc-link-lib=framework=CoreServices");
}
