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

/** Human-readable byte size, e.g. 4.8 MB, 512 KB, 1.2 GB. */
export function fmtSize(bytes: number | null | undefined): string {
  if (!bytes || bytes < 0) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let i = 0;
  let v = bytes;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  const decimals = v >= 100 || i === 0 ? 0 : v >= 10 ? 1 : 2;
  return `${v.toFixed(decimals)} ${units[i]}`;
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
