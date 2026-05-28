interface StatusBarProps {
  status: "online" | "offline" | "reconnecting"
}

const statusConfig = {
  online: { label: "Online", color: "#22c55e" },
  offline: { label: "Offline", color: "#ef4444" },
  reconnecting: { label: "Reconnecting...", color: "#f59e0b" },
} as const

export function StatusBar({ status }: StatusBarProps) {
  const { label, color } = statusConfig[status]

  return (
    <div
      style={{
        display: "flex",
        alignItems: "center",
        gap: "8px",
        padding: "8px 12px",
        marginBottom: "12px",
        borderRadius: "6px",
        background: "#f8f9fa",
        fontSize: "13px",
      }}
    >
      <div
        style={{
          width: "8px",
          height: "8px",
          borderRadius: "50%",
          background: color,
        }}
      />
      <span>Daemon: {label}</span>
    </div>
  )
}
