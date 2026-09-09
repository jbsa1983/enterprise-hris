export function peso(n: number | null | undefined): string {
  if (n === null || n === undefined) return "—";
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(n);
}

export function num(n: number | null | undefined): string {
  if (n === null || n === undefined) return "—";
  return new Intl.NumberFormat("en-PH").format(n);
}

export function storageStatusColor(status: string): string {
  switch (status) {
    case "CRITICAL":
      return "bg-red-100 text-red-700";
    case "WARNING":
      return "bg-orange-100 text-orange-700";
    case "ADVISORY":
      return "bg-yellow-100 text-yellow-700";
    default:
      return "bg-emerald-100 text-emerald-700";
  }
}

export function statusColor(status: string): string {
  switch (status) {
    case "ACTIVE":
      return "bg-emerald-100 text-emerald-700";
    case "PENDING":
      return "bg-yellow-100 text-yellow-700";
    case "SEPARATED":
    case "TERMINATED":
      return "bg-red-100 text-red-700";
    default:
      return "bg-slate-100 text-slate-600";
  }
}
