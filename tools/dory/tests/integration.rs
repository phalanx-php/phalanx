use std::process::Command;

#[test]
fn test_dory_boot_and_sapi() {
    let dory_exe = env!("CARGO_BIN_EXE_dory");

    let output = Command::new(dory_exe)
        .output()
        .expect("Failed to execute dory binary");

    let stdout = String::from_utf8_lossy(&output.stdout);
    let stderr = String::from_utf8_lossy(&output.stderr);

    // Verify SAPI spoofing
    assert!(stdout.contains("SAPI Name: cli"), "Dory must spoof the SAPI as 'cli' for Swoole to initialize. Output:\n{}", stdout);
    
    // Verify Swoole is loaded
    assert!(stdout.contains("Swoole Extension Loaded: Yes"), "Swoole extension must be statically compiled and loaded. Output:\n{}", stdout);

    // Verify coroutines work
    assert!(stdout.contains("Coroutine Scheduler started."), "Coroutine scheduler did not start. Output:\n{}", stdout);
    assert!(stdout.contains("Coroutine woke up."), "Coroutine sleep/wakeup failed. Output:\n{}", stdout);

    assert!(output.status.success(), "Dory binary exited with non-zero status. Stderr:\n{}", stderr);
}
