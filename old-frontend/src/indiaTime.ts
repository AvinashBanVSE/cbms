export const indiaTime = new Intl.DateTimeFormat("en-IN", {
  dateStyle: "medium",
  timeStyle: "medium",
  timeZone: "Asia/Kolkata",
});
export function formatPresentedDate(
  instant: Date,
  format: string,
  timeZone = "Asia/Kolkata",
): string {
  const parts = new Intl.DateTimeFormat("en-IN", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(instant);
  const value = Object.fromEntries(
    parts.map(({ type, value }) => [type, value]),
  );
  return format === "year-month-day"
    ? [value.year, value.month, value.day].join("-")
    : [value.day, value.month, value.year].join("/");
}

export function formatPresentedTimestamp(
  instant: Date,
  clock?: string | null,
  dateFormat?: string | null,
): string {
  if (!clock && !dateFormat) return indiaTime.format(instant);
  const time = new Intl.DateTimeFormat("en-IN", {
    timeZone: "Asia/Kolkata",
    timeStyle: "medium",
    ...(clock ? { hour12: clock === "12-hour" } : {}),
  }).format(instant);
  const date = dateFormat
    ? formatPresentedDate(instant, dateFormat)
    : new Intl.DateTimeFormat("en-IN", {
        timeZone: "Asia/Kolkata",
        dateStyle: "medium",
      }).format(instant);
  return date + " " + time;
}
