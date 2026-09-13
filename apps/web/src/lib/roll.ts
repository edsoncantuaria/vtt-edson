import { updateScene } from "./scene";
export function rollToChat(sceneId: number, formula: string, label?: string) {
  return updateScene(sceneId, "/chat", { text: "/roll " + formula, label });
}
