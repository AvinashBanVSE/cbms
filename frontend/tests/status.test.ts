import { expect, it } from "vitest";
import { installationStage } from "../src/status";

it.each(["setup_required", "owner_required", "ready"])(
  "accepts %s",
  (stage) => {
    expect(installationStage(stage)).toBe(stage);
  },
);
it.each([undefined, null, "unknown", {}, []])(
  "rejects invalid stage %j",
  (stage) => {
    expect(() => installationStage(stage)).toThrow(
      "Unknown installation stage",
    );
  },
);
