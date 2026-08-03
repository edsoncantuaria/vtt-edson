import { Lobby } from './components/Lobby'
import { TableView } from './components/TableView'
import { useSession } from './store/session'

export default function App() {
  const sceneId = useSession((s) => s.sceneId)
  return sceneId ? <TableView /> : <Lobby />
}
