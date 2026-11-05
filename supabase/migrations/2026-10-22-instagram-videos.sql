-- Instagram com VIDEOS (22/10/2026), no padrao da Casa Angelina: a cliente sobe
-- o reels pelo painel em vez de colar o link para buscar a capa. A capa pelo
-- link (insta-capa.php) foi descartada pelo cliente e removida.
--
-- video_url : /videos/ig-<id>-feed-<hash>.mp4 na ereHost (upload-video.php,
--             tipo "feed"). NAO no Supabase Storage: o projeto e compartilhado
--             e o egress de video derrubaria a cota dos outros clientes.
-- poster_url: primeiro quadro do video (jpg), o que o visitante ve antes de
--             clicar. Sem ele o celular teria que baixar o video para mostrar algo.
-- url       : link do post no Instagram, agora OPCIONAL (sem ele, o "Ver no
--             Instagram" leva ao perfil).
-- ordem     : a ordem escolhida no painel.

begin;
alter table po_instagram alter column url drop not null;
alter table po_instagram add column if not exists video_url  text;
alter table po_instagram add column if not exists poster_url text;
-- os 12 itens do modelo anterior (so capa, sem video) saem do site
delete from po_instagram where video_url is null;
commit;
