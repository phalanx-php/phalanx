use std::process::Command;

#[test]
#[ignore = "requires a validated Dory static PHP runtime"]
fn test_dory_boot_and_sapi() {
    let dory_exe = env!("CARGO_BIN_EXE_dory");

    let output = Command::new(dory_exe)
        .output()
        .expect("Failed to execute dory binary");

    let stdout = String::from_utf8_lossy(&output.stdout);
    let stderr = String::from_utf8_lossy(&output.stderr);

    assert!(
        stdout.contains("SAPI Name: ripht"),
        "Dory must report the embedded Ripht SAPI. Output:\n{}",
        stdout
    );

    assert!(
        stdout.contains("Swoole Extension Loaded: Yes"),
        "Swoole extension must be statically compiled and loaded. Output:\n{}",
        stdout
    );

    assert!(
        stdout.contains("Coroutine Scheduler started."),
        "Coroutine scheduler did not start. Output:\n{}",
        stdout
    );
    assert!(
        stdout.contains("Coroutine woke up."),
        "Coroutine sleep/wakeup failed. Output:\n{}",
        stdout
    );

    assert!(
        output.status.success(),
        "Dory binary exited with non-zero status. Stderr:\n{}",
        stderr
    );
}
