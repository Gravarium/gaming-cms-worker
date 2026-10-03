/** Tracks the exact local snapshot sent to the server while typing continues. */
export class AutosaveState {
  constructor(savedSnapshot) {
    this.savedSnapshot = savedSnapshot;
    this.inFlight = null;
    this.blocked = false;
  }

  isDirty(current) { return current !== this.savedSnapshot; }

  begin(current) {
    if (this.blocked || this.inFlight !== null || !this.isDirty(current)) return null;
    this.inFlight = current;
    return current;
  }

  success(snapshot) {
    if (this.inFlight !== snapshot) return false;
    this.savedSnapshot = snapshot;
    this.inFlight = null;
    return true;
  }

  failure(conflict = false) {
    this.inFlight = null;
    if (conflict) this.blocked = true;
  }
}
