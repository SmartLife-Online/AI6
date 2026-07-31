# AI6

AI6 verwaltet Git-native Softwaretickets, lässt sie von Menschen freigeben und orchestriert anschließend getrennte LLM-Sitzungen für Implementierung und Review. Geplant ist ein modularer Laravel-Monolith mit einer Codebasis und klar getrennten Prozessrollen.

## Aktueller Stand

Das Repository befindet sich vor der Implementierung. Laravel-Anwendung, `composer.json`, Abhängigkeiten, Tests und die PHP-Toolchain werden erst durch `AI6-001` angelegt.

Von 44 geplanten Tickets sind derzeit zwölf als Detailtickets erzeugt — `AI6-001` bis `AI6-004`, `AI6-005A`, `AI6-005B` sowie `AI6-006A` bis `AI6-006F`; die übrigen Arbeitspakete liegen als Blueprints im Implementierungsplan vor.

Ausschließlich `AI6-001` ist gegen den realen Repositoryzustand abgeleitet. Die elf übrigen Detailtickets sind vorab abgeleitet (`ahead-derived` nach Plan §13.6): Sie benennen Pfade, Klassen, Kommandos und Tests, die erst mit ihren Vorgängern entstehen. Sie stehen auf `status: todo`, sind nicht umsetzungsbereit und dürfen vor dem verpflichtenden Rebase gegen den dann realen Repositoryzustand weder freigegeben noch beansprucht werden.

## Einstieg

- [Implementierungsplan V1.6.20](docs/AI6_IMPLEMENTATION_PLAN.md) — normative Quelle für Anforderungen, Architektur, Meilensteine und Ticket-Blueprints.
- [Ticket-Template V1](docs/AI6_TICKET_TEMPLATE_V1.md) — verbindliches Format sowie Erzeugungs- und Umsetzungsvertrag für Detailtickets.
- [Ticketübersicht](tickets/README.md) — aktueller Erzeugungsstand, Abhängigkeiten und Arbeitsweise; keine autoritative Statusquelle.
- [Agentenanweisungen](AGENTS.md) — verbindliche Regeln für agentische LLMs in diesem Repository.
