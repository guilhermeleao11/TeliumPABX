-- Auditoria passa a ser módulo do administrador.
--
-- A trilha guarda quem mexeu em quê, de qual IP e a que horas. Ela
-- morava em "rel.logs", dentro de Relatórios — e Relatórios é entregue
-- por curinga ("rel.*") ao supervisor e ao auditor. Na prática, dois
-- perfis liam o registro de quem mexeu na central, incluindo as próprias
-- entradas. Agora é "admin.auditoria", que nenhum curinga de outro
-- perfil alcança.
--
-- Idempotente: rodar de novo não faz nada.

DELETE FROM perfil_modulos WHERE modulo = 'rel.logs';

-- Quem tenha recebido a chave nova por engano em algum perfil que não
-- seja o administrador perde o acesso; o administrador entra por '*'.
DELETE pm FROM perfil_modulos pm
  JOIN perfis p ON p.id = pm.perfil_id
 WHERE pm.modulo = 'admin.auditoria' AND p.chave <> 'admin';
