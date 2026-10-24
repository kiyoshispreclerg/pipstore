**Português** | [English](README.md)

# PipStore

Site simples para um autor publicar suas histórias.

Gerencia séries, livros e capítulos, em múltiplos idiomas. Permite personalizar a cor de destaque e oferece ao leitor controle sobre a experiência: modo claro/escuro, fonte serifada ou sem serifa, tamanho do texto.

Mais imersão nas histórias com ilustrações e trilha sonora opcionais.

Destaca o último capítulo lido em cada livro, facilitando retomar a leitura em seções separadas. Inclui também uma seção de links para bio do autor.

Feito com assistência de LLM, mas todo código é revisado antes de ir pro ar.

## Instalação

Não há instalador web; a configuração leva três passos. Requisitos: PHP com `mysqli` e MySQL/MariaDB.

1. **Extraia os arquivos** na pasta pública do servidor (ex.: `public_html/`, `htdocs/` ou `/var/www/html/`). Garanta que a pasta `uploads/` tenha permissão de escrita para o servidor web.
2. **Crie o banco** importando o `install.sql` (phpMyAdmin → *Importar*, ou `mysql -u root -p < install.sql`). Ele cria o banco `kiyoshipip` e todas as tabelas. Se sua hospedagem já fornece um banco pronto, remova as primeiras linhas (`CREATE DATABASE` e `USE`) e importe nele.
3. **Edite o `config.php`** com os dados de acesso ao banco: `DB_HOST`, `DB_USER`, `DB_PASS` e `DB_NAME`. Aproveite para mudar o `SITE_NAME`, se quiser.

Depois abra `https://seu-site/admin.php` e entre com a senha padrão **`changeme`**. Troque-a imediatamente. Migrações pendentes da pasta `migrations/` são aplicadas automaticamente ao abrir o admin. O site público fica em `index.php`.
