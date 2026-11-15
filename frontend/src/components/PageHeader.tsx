export function PageHeader({ title }: { title: string }) {
  return <h1 className="mb-4 text-2xl font-semibold tracking-tight">{title}</h1>
}

export function EmptyState({ children }: { children: React.ReactNode }) {
  return (
    <p className="rounded-lg border border-dashed bg-card p-6 text-center text-sm text-muted-foreground">{children}</p>
  )
}
