interface TabBadgeProps {
  connected: boolean
}

export function TabBadge({ connected }: TabBadgeProps) {
  return (
    <span
      style={{
        display: "inline-block",
        padding: "2px 6px",
        borderRadius: "4px",
        fontSize: "11px",
        fontWeight: 600,
        background: connected ? "#dcfce7" : "#f3f4f6",
        color: connected ? "#166534" : "#6b7280",
      }}
    >
      {connected ? "Connected" : "Disconnected"}
    </span>
  )
}
