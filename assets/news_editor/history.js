/** Bounded editor snapshots. Text input bursts are grouped; structural edits are separate steps. */
export class EditorHistory {
  constructor(document, limit = 100) {
    this.states = [document];
    this.index = 0;
    this.limit = limit;
    this.lastInputAt = -Infinity;
    this.lastWasInput = false;
  }

  record(document, {input = false, time = Date.now()} = {}) {
    if (document === this.states[this.index]) return;
    if (input && this.lastWasInput && time - this.lastInputAt < 800 && this.index === this.states.length - 1) {
      this.states[this.index] = document;
    } else {
      this.states.length = this.index + 1;
      this.states.push(document);
      if (this.states.length > this.limit) this.states.shift();
      this.index = this.states.length - 1;
    }
    this.lastWasInput = input;
    this.lastInputAt = input ? time : -Infinity;
  }

  undo() {
    this.lastWasInput = false;
    if (this.index === 0) return null;
    return this.states[--this.index];
  }

  redo() {
    this.lastWasInput = false;
    if (this.index >= this.states.length - 1) return null;
    return this.states[++this.index];
  }
}
