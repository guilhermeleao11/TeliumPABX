-- O operador volta a enxergar só o próprio ramal.
--
-- O perfil recebia "apps.correiovoz" e "apps.sigame" — que NÃO são as
-- telas pessoais. São as telas de administração desses serviços, e elas
-- listam TODOS os ramais da central, com senha da caixa postal e destino
-- de siga-me editáveis. Na prática, qualquer atendente podia ler e
-- trocar o correio de voz e o desvio de qualquer colega.
--
-- O que o operador precisa já estava no lugar certo: "pcu.*", que é
-- "Meu Painel" e só alcança o ramal vinculado à conta dele —
-- /api/me/correiovoz e /api/me/sigame resolvem o ramal pela sessão, não
-- por parâmetro.
--
-- Idempotente: rodar de novo não faz nada.

DELETE pm FROM perfil_modulos pm
  JOIN perfis p ON p.id = pm.perfil_id
 WHERE p.chave = 'operador'
   AND pm.modulo IN ('apps.correiovoz', 'apps.sigame');
