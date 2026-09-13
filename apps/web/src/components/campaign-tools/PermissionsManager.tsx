import { useCallback, useEffect, useState } from "react";
import type { Role } from "@vtt/core";
import { api } from "../../lib/api";
import { isManagerRole, useSession } from "../../store/session";

type Member = {
  id: number;
  user_id: number;
  role: Role;
  permissions: string[];
  user: { id: number; name: string; email: string };
};
type Grant = {
  id: number;
  user_id: number;
  resource_type: string;
  resource_id: number;
  permission: "view" | "edit" | "manage";
};

export function PermissionsManager() {
  const { campaignId, role, user, setError } = useSession();
  const [members, setMembers] = useState<Member[]>([]);
  const [grants, setGrants] = useState<Grant[]>([]);
  const [userId, setUserId] = useState("");
  const [resourceType, setResourceType] = useState("actor");
  const [resourceId, setResourceId] = useState("");
  const [permission, setPermission] = useState<Grant["permission"]>("view");

  const refresh = useCallback(async () => {
    if (!campaignId) return;
    try {
      const memberResult = await api<{ members: Member[] }>(`/campaigns/${campaignId}/members`);
      setMembers(memberResult.members);
      if (isManagerRole(role)) {
        const grantResult = await api<{ permissions: Grant[] }>(
          `/campaigns/${campaignId}/resource-permissions`,
        );
        setGrants(grantResult.permissions);
      }
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível carregar as permissões.");
    }
  }, [campaignId, role, setError]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  async function updateRole(member: Member, nextRole: Role) {
    if (!campaignId) return;
    try {
      await api(`/campaigns/${campaignId}/members/${member.id}`, {
        method: "PATCH",
        body: JSON.stringify({ role: nextRole }),
      });
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível alterar o papel.");
    }
  }

  async function addGrant() {
    if (!campaignId || !userId || !resourceId) return;
    try {
      await api(`/campaigns/${campaignId}/resource-permissions`, {
        method: "POST",
        body: JSON.stringify({
          userId: Number(userId),
          resourceType,
          resourceId: Number(resourceId),
          permission,
        }),
      });
      setResourceId("");
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível conceder a permissão.");
    }
  }

  async function removeGrant(id: number) {
    if (!campaignId) return;
    try {
      await api(`/campaigns/${campaignId}/resource-permissions/${id}`, { method: "DELETE" });
      await refresh();
    } catch (error) {
      setError(error instanceof Error ? error.message : "Não foi possível revogar a permissão.");
    }
  }

  return (
    <section>
      <h3>Papéis e permissões</h3>
      <div className="sheet-items">
        {members.map((member) => (
          <article key={member.id}>
            <div>
              <h4>{member.user.name}</h4>
              <small>{member.user.email}</small>
            </div>
            {role === "gm" && member.user_id !== user?.id ? (
              <select
                value={member.role}
                onChange={(event) => void updateRole(member, event.target.value as Role)}
              >
                <option value="assistant">Assistente / co-GM</option>
                <option value="player">Jogador</option>
                <option value="observer">Observador</option>
              </select>
            ) : (
              <b>{member.role}</b>
            )}
          </article>
        ))}
      </div>
      {isManagerRole(role) && (
        <details>
          <summary>Permissão por documento</summary>
          <div className="editor-grid">
            <label>
              Pessoa
              <select value={userId} onChange={(event) => setUserId(event.target.value)}>
                <option value="">Escolha</option>
                {members
                  .filter((member) => member.user_id !== user?.id)
                  .map((member) => (
                    <option key={member.id} value={member.user_id}>
                      {member.user.name}
                    </option>
                  ))}
              </select>
            </label>
            <label>
              Recurso
              <select
                value={resourceType}
                onChange={(event) => setResourceType(event.target.value)}
              >
                <option value="actor">Ficha</option>
                <option value="journal">Diário</option>
                <option value="scene">Cena</option>
                <option value="asset">Asset</option>
                <option value="macro">Macro</option>
              </select>
            </label>
            <label>
              ID
              <input
                type="number"
                min={1}
                value={resourceId}
                onChange={(event) => setResourceId(event.target.value)}
              />
            </label>
            <label>
              Nível
              <select
                value={permission}
                onChange={(event) => setPermission(event.target.value as Grant["permission"])}
              >
                <option value="view">Ver</option>
                <option value="edit">Editar</option>
                <option value="manage">Gerenciar</option>
              </select>
            </label>
          </div>
          <button onClick={() => void addGrant()}>Conceder</button>
          {grants.map((grant) => (
            <p key={grant.id}>
              {members.find((member) => member.user_id === grant.user_id)?.user.name ??
                grant.user_id}{" "}
              · {grant.resource_type} #{grant.resource_id} · {grant.permission}{" "}
              <button onClick={() => void removeGrant(grant.id)}>Revogar</button>
            </p>
          ))}
        </details>
      )}
    </section>
  );
}
