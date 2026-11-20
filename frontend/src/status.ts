export type Stage = "setup_required" | "owner_required" | "ready";

export function installationStage(value: unknown): Stage {
  if (
    value === "setup_required" ||
    value === "owner_required" ||
    value === "ready"
  )
    return value;
  throw new Error("Unknown installation stage.");
}
