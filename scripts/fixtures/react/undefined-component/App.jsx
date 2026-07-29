import Icon from './Icon.jsx';

export default function App() {
  const icons = { home: Icon };
  const Chosen = icons['recipes']; // typo'd key -> undefined at runtime
  return <Chosen />;
}
