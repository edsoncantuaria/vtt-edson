import { lazy, Suspense, useEffect, useState } from "react";
import { Lobby } from "./components/Lobby";
import { Brand } from "./components/Icon";
import { api, ApiError, getToken, setToken } from "./lib/api";
import { useSession, type User } from "./store/session";
import { SheetPopout } from "./components/SheetPopout";
const TableView = lazy(() =>
  import("./components/TableView").then((module) => ({
    default: module.TableView,
  })),
);
export default function App() {
  const sceneId = useSession((s) => s.sceneId);
  const [loading, setLoading] = useState(true);
  const [failure, setFailure] = useState(false);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let active = true;
    const token = getToken();
    if (!token) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setFailure(false);
    api<{ user: User }>("/me")
      .then(({ user }) => {
        if (active && getToken() === token) useSession.getState().setAuth(user, token);
      })
      .catch((e) => {
        if (!active || getToken() !== token) return;
        if (e instanceof ApiError && e.status === 401) {
          setToken(null);
          useSession.getState().clearAuth();
        } else setFailure(true);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [attempt]);
  useEffect(() => {
    const unauthorized = () => {
      setToken(null);
      useSession.getState().clearAuth();
    };
    const navigate = () => {
      if (!location.hash.startsWith("#mesa/" + useSession.getState().roomCode))
        useSession.getState().leaveScene();
    };
    const storage = (e: StorageEvent) => {
      if (e.key === "vtt_token") {
        useSession.getState().clearAuth();
        setAttempt((n) => n + 1);
      }
    };
    window.addEventListener("vtt:unauthorized", unauthorized);
    window.addEventListener("hashchange", navigate);
    window.addEventListener("storage", storage);
    return () => {
      window.removeEventListener("vtt:unauthorized", unauthorized);
      window.removeEventListener("hashchange", navigate);
      window.removeEventListener("storage", storage);
    };
  }, []);
  if (loading || failure)
    return (
      <main className="boot">
        <Brand />
        <p>
          {failure ? "Não foi possível conectar à sua conta." : "Preparando sua próxima aventura…"}
        </p>
        {failure && (
          <button className="primary" onClick={() => setAttempt((n) => n + 1)}>
            Tentar novamente
          </button>
        )}
      </main>
    );
  const sheetMatch = location.hash.match(/^#mesa\/([^/]+)\/ficha\/(\d+)$/);
  if (!sceneId) return <Lobby />;
  if (sheetMatch) return <SheetPopout actorId={Number(sheetMatch[2])} />;
  return (
    <Suspense
      fallback={
        <main className="boot" role="status">
          Abrindo sua mesa…
        </main>
      }
    >
      <TableView key={sceneId} />
    </Suspense>
  );
}
