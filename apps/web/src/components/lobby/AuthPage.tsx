import type { FormEvent } from "react";
import { Brand, Icon } from "../Icon";

export type AuthMode = "login" | "register";

export function AuthPage({
  mode,
  setMode,
  name,
  setName,
  email,
  setEmail,
  password,
  setPassword,
  busy,
  error,
  onSubmit,
}: {
  mode: AuthMode;
  setMode: (mode: AuthMode) => void;
  name: string;
  setName: (value: string) => void;
  email: string;
  setEmail: (value: string) => void;
  password: string;
  setPassword: (value: string) => void;
  busy: boolean;
  error: string | null;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
  return (
    <main className="auth-page">
      <section className="auth-story">
        <Brand />
        <div className="auth-story__copy">
          <span className="eyebrow">O MUNDO É SEU. A HISTÓRIA É DE TODOS.</span>
          <h1>
            Grandes histórias
            <br />
            começam à mesa.
          </h1>
          <p>Reúna seu grupo. Abra os mapas. Deixe os dados decidirem o resto.</p>
          <div className="auth-story__tags">
            <span>
              <Icon name="map" size={16} /> Mapas vivos
            </span>
            <span>
              <Icon name="shield" size={16} /> Fichas D&D 5e
            </span>
            <span>
              <Icon name="dice" size={16} /> Dados compartilhados
            </span>
          </div>
        </div>
        <p className="auth-story__foot">
          Feito para quem vive a aventura, de ambos os lados do escudo.
        </p>
      </section>
      <section className="auth-form-area">
        <form className="auth-form" onSubmit={onSubmit}>
          <span className="eyebrow">SEU LUGAR À MESA</span>
          <h2>{mode === "login" ? "Bem-vindo de volta." : "Toda aventura tem um início."}</h2>
          <p>
            {mode === "login"
              ? "Entre para continuar suas campanhas."
              : "Crie sua conta para mestrar ou jogar."}
          </p>
          <div className="segmented">
            <button type="button" aria-pressed={mode === "login"} onClick={() => setMode("login")}>
              Entrar
            </button>
            <button
              type="button"
              aria-pressed={mode === "register"}
              onClick={() => setMode("register")}
            >
              Criar conta
            </button>
          </div>
          {mode === "register" && (
            <label>
              Como podemos chamar você?
              <input
                autoComplete="name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                required
                maxLength={80}
                placeholder="Seu nome de aventureiro"
              />
            </label>
          )}
          <label>
            E-mail
            <input
              type="email"
              autoComplete="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
              placeholder="voce@exemplo.com"
            />
          </label>
          <label>
            Senha
            <input
              type="password"
              autoComplete={mode === "login" ? "current-password" : "new-password"}
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
              minLength={mode === "register" ? 8 : undefined}
              placeholder={mode === "login" ? "Sua senha" : "Pelo menos 8 caracteres"}
            />
          </label>
          {error && (
            <p className="notice notice--error" role="alert">
              {error}
            </p>
          )}
          <button className="primary auth-form__submit" disabled={busy}>
            {busy ? "Conectando…" : mode === "login" ? "Entrar na aventura" : "Criar minha conta"}
            <Icon name="arrow" size={18} />
          </button>
          <p className="auth-form__note">
            <Icon name="users" size={16} /> Uma conta. Todas as suas mesas.
          </p>
        </form>
        <p className="auth-footer">D&D 5e · Edições 2014 e 2024</p>
      </section>
    </main>
  );
}
