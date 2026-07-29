const todo = {
  async renderView() {
    return this.loadState().then((state) => `<div>${state.length} tasks</div>`);
  },
  async loadState() {
    return api.list('tasks');
  },
};
