const toggle = document.querySelector('#menu-toggle');
const sidebar = document.querySelector('#sidebar');
function closeMenu() {
  sidebar?.classList.remove('open');
  toggle?.setAttribute('aria-expanded', 'false');
}
toggle?.addEventListener('click', () => {
  const open = sidebar.classList.toggle('open');
  toggle.setAttribute('aria-expanded', String(open));
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeMenu();
});
document.addEventListener('click', (event) => {
  if (!sidebar?.contains(event.target) && !toggle?.contains(event.target)) closeMenu();
});
const role = document.querySelector('#role');
const superior = document.querySelector('#superior_id');
function updateSuperior() {
  if (superior) superior.required = role.value === 'funcionario';
}
role?.addEventListener('change', updateSuperior);
if (role) updateSuperior();
