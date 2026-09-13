import { useEffect, useRef, useState } from "react";
import { useSession } from "../store/session";
import { Icon } from "./Icon";

export function AmbiencePlayer() {
  const audio = useSession((s) => s.state.audio);
  const element = useRef<HTMLAudioElement>(null);
  const [playing, setPlaying] = useState(false);
  const [muted, setMuted] = useState(false);
  useEffect(() => {
    setPlaying(false);
    if (element.current) {
      element.current.pause();
      element.current.currentTime = 0;
    }
  }, [audio.url]);
  useEffect(() => {
    if (element.current) element.current.volume = muted ? 0 : audio.volume;
  }, [audio.volume, muted]);
  if (!audio.url) return null;
  return (
    <div className="ambience-player">
      <audio ref={element} src={audio.url} loop={audio.loop} onEnded={() => setPlaying(false)} />
      <button
        onClick={() => {
          const player = element.current;
          if (!player) return;
          if (playing) {
            player.pause();
            setPlaying(false);
          } else {
            void player
              .play()
              .then(() => setPlaying(true))
              .catch(() => setPlaying(false));
          }
        }}
      >
        <Icon name="music" size={16} />
        {playing ? "Pausar ambiente" : "Iniciar ambiente"}
      </button>
      <button
        className="icon-button"
        aria-label={muted ? "Ativar som ambiente" : "Silenciar ambiente"}
        onClick={() => setMuted(!muted)}
      >
        {muted ? "×" : "♪"}
      </button>
    </div>
  );
}
