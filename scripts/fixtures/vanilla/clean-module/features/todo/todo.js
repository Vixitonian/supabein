const todo = {
  async renderView() {
    const items = await api.list('tasks');
    const rows = items
      .map((item) => `<li data-id="${item.id}">${item.title} <button class="delete-btn" data-id="${item.id}">Delete</button></li>`)
      .join('');
    return `<ul>${rows}</ul>`;
  },
  wireEvents(container) {
    container.querySelectorAll('.delete-btn').forEach((btn) => {
      btn.addEventListener('click', () => todo.deleteTask(btn.dataset.id));
    });
  },
  deleteTask(id) {
    return api.remove('tasks', id);
  },
};
