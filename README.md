# OJSBR Sitemap — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.1.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/ojsbrSitemap/releases/download/1.1.0.0/ojsbrSitemap-1.1.0.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS) 3.5** that improves the **sitemap of a journal**
(`/index.php/<journal>/sitemap`) for search engines: it takes out the pages that are not content,
adds the pages OJS leaves out, dates every page by its content and links each page to the same
page in the other languages of the journal.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| Application | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.0.0 |

Requires PHP 8.2 or later. OMP and OPS are not supported: there the plugin does nothing.

## The problem

The sitemap OJS builds lists pages a search engine should not spend time on (login,
registration, search, the current issue, which is the same page as the latest issue, and each
galley, a file or viewer of an article page already listed), leaves out pages with content
(the editorial masthead, the privacy statement, the categories), has no `lastmod`, so a search
engine cannot tell what changed, and in a multilingual journal gives no hint that the same page
exists in other languages.

## What the plugin does

**Removed** from the sitemap:

- `login`, `user/register`, `search` and `issue/current`;
- the galleys: `article/view/<article>/<galley>` (OJS 3.5.0-6) and, in later 3.5 releases that list a PDF by its download address, `article/download/<article>/<galley>`.

**Added:**

- **`lastmod`** (a day, `Y-m-d`), only from a real date of the content:
  - article: the last modification of its current publication, or its publication date;
  - issue: its last modification, or its publication date;
  - announcement: the day it was posted;
  - home page and archive: the latest of those dates;
  - every other page: no `lastmod`. The current date is never used.
- `about/editorialMasthead`;
- `about/privacy`, when the journal has a privacy statement;
- `catalog/category/<path>`, for each category of the journal;
- in a journal with more than one language, an **`xhtml:link rel="alternate" hreflang`** on every
  address for each language of the journal, plus `x-default` for the primary language
  (`pt_BR` becomes `pt-BR`; the namespace `xmlns:xhtml` is declared on `urlset`).

No address is listed twice. The addresses are built with the same calls OJS uses to build the
sitemap, so they match it exactly. The sitemap index of the site (`/index.php/index/sitemap`) is
not changed.

### Served from a file, generated ahead of time

OJS builds the sitemap at each request, and for a large journal that takes longer than search
engines wait (34 to 44 s for 5,400 articles, past the 30 s a PHP request of a hosting account may
run). The plugin generates the sitemap of each journal in each of its languages with OJS's
**scheduled tasks**, from the command line, and the sitemap page sends that file at once:

- four times an hour (at 5, 20, 35 and 50 minutes), the task checks each journal where the
  plugin is enabled and generates its sitemaps again when the content changed (articles, issues or announcements
  published, changed or removed, languages of the journal) or when they are a day old;
- a new file replaces the old one only when it is complete, so the page never waits for it;
- when there is no file for the language of the request, or the file is more than a week old
  (the scheduled tasks are not running), the page builds the sitemap as OJS does;
- the files are kept in `cache/ojsbrSitemap/` of the installation.

**Requires the scheduled tasks of OJS 3.5** (`lib/pkp/tools/scheduler.php run` every minute in the
crontab, as the OJS documentation recommends). Without them the plugin still works, building the
sitemap at each request.

## Installation

Upload the package in **Settings › Website › Plugins › Upload A New Plugin**, or extract the
folder into `plugins/generic/` (giving `plugins/generic/ojsbrSitemap/`). Then enable
**Improved Sitemap** in the *Generic* plugins list of each journal. There is nothing to configure.

## How it works (technical)

- `SitemapHandler::createJournalSitemap`, which OJS calls with the finished `DOMDocument`: only
  the XML of the sitemap is changed. No core file is patched and no template is replaced.
- `HasTaskScheduler::registerSchedules()` registers `classes/SitemapTask.php`, and `LoadHandler`
  gives the sitemap page of a journal to `classes/SitemapPageHandler.php`, which extends the
  sitemap handler of OJS and falls back to it.
- `classes/SitemapTransformer.php` makes the changes from the lists it is given (addresses to
  remove and add, dates, the base address of the journal in each language) and needs no database,
  so it is tested on its own.
- The plugin reads the dates with one query for the articles, one for the galleys and one for the
  issues, not one per article. When reading fails, the error is logged and the sitemap of OJS is
  served unchanged.

## Tests

PHPUnit (`tests/`, on `PKPTestCase`) and Cypress (`cypress/tests/functional/`), run by PKP's
continuous integration on OJS with PHP 8.2/8.3 and MySQL/PostgreSQL:

- the transformer on a document built as OJS builds it: removed and added addresses, order kept,
  no duplicates, escaping, `lastmod` only from given dates, the latest date on the home page and
  the archive, an existing `lastmod` kept, language links and `x-default`, nothing for a journal
  with one language or an address outside the journal; every document checked against the
  structure of the sitemap protocol (`tests/fixtures/sitemap-structure.xsd`);
- the sitemap OJS builds for a journal of the database: pages removed, editorial masthead added,
  no duplicates, `lastmod` of an article and an issue compared with the database, language links;
- the scheduled task: the file it writes has the entries of the sitemap the page builds, the page
  sends it, and nothing is generated again while the content is the same; the files themselves
  (served until a week old, generated again when the content changes or once a day, languages
  removed, no temporary file left);
- in the browser: the sitemap an anonymous visitor gets.

The database tests fail when the suite runs without the plugin (`OJSBR_SITEMAP_WITHOUT=1`).

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring
it in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible
for the published releases.

## Contributing

Issues and pull requests are welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS) 3.5** que melhora o **sitemap da revista**
(`/index.php/<revista>/sitemap`) para os buscadores: tira as páginas que não são conteúdo,
acrescenta as que o OJS deixa de fora, data cada página pelo seu conteúdo e liga cada página à
mesma página nos outros idiomas da revista.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade e branches

| Aplicação | Branch | Release do plugin |
|-----------|--------|-------------------|
| OJS 3.5.x | `stable-3_5_0` *(padrão)* | 1.1.0.0 |

Requer PHP 8.2 ou superior. OMP e OPS não são atendidos: neles o plugin não faz nada.

### O problema

O sitemap que o OJS monta lista páginas em que o buscador não deveria gastar tempo (acesso,
cadastro, busca, a edição atual, que é a mesma página da última edição, e cada composição, que é
um arquivo ou visualizador de uma página de artigo já listada), deixa de fora páginas com
conteúdo (o expediente editorial, a declaração de privacidade, as categorias), não tem `lastmod`,
e o buscador não sabe o que mudou, e numa revista multilíngue não indica que a mesma página
existe em outros idiomas.

### O que o plugin faz

**Retira** do sitemap:

- `login`, `user/register`, `search` e `issue/current`;
- as composições: `article/view/<artigo>/<composição>` (OJS 3.5.0-6) e, nas versões 3.5 seguintes, que listam o PDF pelo endereço de download, `article/download/<artigo>/<composição>`.

**Acrescenta:**

- **`lastmod`** (um dia, `A-m-d`), só a partir de uma data real do conteúdo:
  - artigo: a última modificação da publicação atual, ou a data de publicação;
  - edição: a última modificação, ou a data de publicação;
  - notícia: o dia em que foi publicada;
  - página inicial e arquivo: a maior dessas datas;
  - demais páginas: sem `lastmod`. A data atual nunca é usada.
- `about/editorialMasthead`;
- `about/privacy`, quando a revista tem declaração de privacidade;
- `catalog/category/<caminho>`, para cada categoria da revista;
- numa revista com mais de um idioma, um **`xhtml:link rel="alternate" hreflang`** em cada
  endereço para cada idioma da revista, mais `x-default` para o idioma principal (`pt_BR` vira
  `pt-BR`; o namespace `xmlns:xhtml` é declarado no `urlset`).

Nenhum endereço aparece duas vezes. Os endereços são gerados com as mesmas chamadas que o OJS usa
para montar o sitemap e casam exatamente com os dele. O índice de sitemaps do site
(`/index.php/index/sitemap`) não muda.

#### Servido de um arquivo gerado antes

O OJS monta o sitemap a cada acesso, e numa revista grande isso leva mais do que os buscadores
esperam (34 a 44 s para 5.400 artigos, além dos 30 s que uma requisição PHP de hospedagem pode
rodar). O plugin gera o sitemap de cada revista em cada idioma pelas **tarefas agendadas** do OJS,
em linha de comando, e a página do sitemap envia esse arquivo na hora:

- quatro vezes por hora (aos 5, 20, 35 e 50 minutos), a tarefa olha cada revista com o plugin
  ligado e gera de novo os sitemaps quando o conteúdo mudou (artigos, edições ou notícias publicados, alterados ou
  retirados, idiomas da revista) ou quando eles têm um dia;
- o arquivo novo só substitui o antigo quando está completo, e a página nunca espera por ele;
- sem arquivo para o idioma do acesso, ou com arquivo de mais de uma semana (as tarefas agendadas
  pararam), a página monta o sitemap como o OJS;
- os arquivos ficam em `cache/ojsbrSitemap/` da instalação.

**Requer as tarefas agendadas do OJS 3.5** (`lib/pkp/tools/scheduler.php run` a cada minuto no
crontab, como recomenda a documentação do OJS). Sem elas o plugin continua funcionando, montando o
sitemap a cada acesso.

### Instalação

Envie o pacote em **Configurações › Website › Plugins › Enviar um novo plugin**, ou extraia a pasta
em `plugins/generic/` (ficando `plugins/generic/ojsbrSitemap/`). Depois ative o **Sitemap
aprimorado** na lista de plugins *Genéricos* de cada revista. Não há nada para configurar.

### Testes

PHPUnit e Cypress, rodados pela integração contínua da PKP no OJS com PHP 8.2/8.3 e
MySQL/PostgreSQL: o transformador sobre um documento montado como o OJS monta (endereços
retirados e acrescentados, ordem mantida, sem duplicados, escape, `lastmod` só de datas dadas, a
maior data na página inicial e no arquivo, links de idioma e `x-default`, nada numa revista de um
idioma só), sempre conferido contra a estrutura do protocolo de sitemap; o sitemap que o OJS monta
para uma revista do banco, com o `lastmod` de um artigo e de uma edição comparado com o banco; e,
no navegador, o sitemap que um visitante anônimo recebe. Os testes com banco falham quando a
suíte roda sem o plugin (`OJSBR_SITEMAP_WITHOUT=1`).

### Idiomas

Interface do plugin em 38 idiomas; português (Brasil e Portugal), inglês, espanhol, francês
(França e Canadá), italiano, alemão, catalão, galego e neerlandês revisados, os demais marcados
como *fuzzy*.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
