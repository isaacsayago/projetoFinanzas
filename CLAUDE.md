# CLAUDE.md

Este arquivo orienta o Claude Code (claude.ai/code) ao trabalhar com o código deste repositório.

## Visão Geral

**Finanzas** é uma aplicação web de controle financeiro pessoal, feita em PHP puro com MySQL. Não possui sistema de build, gerenciador de pacotes nem framework de testes — roda diretamente em um servidor PHP (ex: XAMPP/WAMP) com banco de dados MySQL chamado `financeiro`.

## Como Rodar

Servir via Apache/PHP (XAMPP, WAMP ou similar) apontando para este diretório. Requer PHP 7.4+ e MySQL. A conexão com o banco está em `127.0.0.1`, com credenciais em `conexao.php` / `conexao_pdo.php`.

## Arquitetura

Todas as páginas são arquivos PHP independentes com CSS e JS inline (sem frameworks externos, sem etapa de build).

### Conexões com o Banco
- **`conexao.php`** — conexão mysqli (usada por `processa_login.php`)
- **`conexao_pdo.php`** — conexão PDO (usada por `dashboard.php`, `usuarios.php`, `monthly_data.php`)

### Páginas e Fluxo
- **`index.php`** — Página de login. Envia formulário para `processa_login.php`.
- **`processa_login.php`** — Autentica via `password_verify()`, salva variáveis de sessão (`id`, `nome`, `nivel`) e redireciona para `dashboard.php`.
- **`dashboard.php`** — Página principal. Gerencia CRUD de despesas/receitas, filtro por período, "copiar do mês anterior", alternar status de pago. Ações via query params (`?action=delete&id=X`) ou POST. Contém resumos financeiros e gráfico (busca dados de `monthly_data.php`).
- **`monthly_data.php`** — Endpoint JSON que retorna dados mensais agregados de despesas/receitas para o gráfico. Linha do tempo: 3 meses para trás até 8 meses para frente.
- **`usuarios.php`** — Gestão de usuários (somente admin). Permite criar usuários, alterar senhas e nível de acesso. Protegido por `$_SESSION['nivel'] === 'adm'`.
- **`logout.php`** — Destrói a sessão e redireciona para o login.

### Tabelas do Banco
- **`usuarios`** — colunas: `id`, `nome`, `email`, `senha` (hash bcrypt), `nivel` (`adm`|`user`), `created_at`
- **`expenses`** — colunas: `id`, `user_id`, `name`, `amount`, `due_date`, `type` (`expense`|`income`), `planned`, `period` (YYYY-MM), `paid`, `payment_date`

### Padrões Importantes
- Todos os dados são filtrados pelo `user_id` do usuário logado — cada usuário vê apenas seus próprios registros.
- Datas são armazenadas como `Y-m-d` no banco e exibidas como `d/m/Y` na interface. O helper `dataParaBanco()` aceita ambos os formatos.
- Moeda em BRL (R$), formatada pelo helper `money()`.
- A coluna `period` (formato YYYY-MM) é o principal mecanismo de agrupamento/filtro nas visualizações mensais.

## Problemas Conhecidos

- Credenciais do banco estão hardcoded em `conexao.php` e `conexao_pdo.php` — devem ser movidas para variáveis de ambiente ou arquivo de configuração fora da raiz web.
