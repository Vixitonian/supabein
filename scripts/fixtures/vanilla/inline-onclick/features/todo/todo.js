const todo = {
  async renderView() {
    const items = await api.list('tasks');
    const rows = items
      .map((item) => `<li>${item.title} <button onclick="todo.deleteTask(${item.id})">Delete</button></li>`)
      .join('');
    return `<ul>${rows}</ul>`;
  },
  deleteTask(id) {
    return api.remove('tasks', id);
  },
};
