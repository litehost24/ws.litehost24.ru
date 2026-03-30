export const MODE_PRESETS = {
  low: {
    reasoning_effort: "low",
    temperature: 0.1,
    max_output_tokens: 500,
  },
  medium: {
    reasoning_effort: "medium",
    temperature: 0.1,
    max_output_tokens: 1200,
  },
  high: {
    reasoning_effort: "high",
    temperature: 0.1,
    max_output_tokens: 2200,
  },
  xhigh: {
    reasoning_effort: "high",
    temperature: 0.0,
    max_output_tokens: 3500,
  },
};

export function isMode(value) {
  return Object.prototype.hasOwnProperty.call(MODE_PRESETS, value);
}
