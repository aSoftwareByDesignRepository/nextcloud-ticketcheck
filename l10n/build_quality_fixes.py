#!/usr/bin/env python3
"""Build _quality_fixes_{lang}.json for TicketCheck formal B2B quality pass."""
from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

L10N = Path(__file__).parent
APPS = L10N.parents[1]
ROOT = L10N.parents[2]
LOCALES = ["de", "fr", "es", "da", "nl", "it", "pl", "sv", "nb", "pt_BR"]
SEED_APPS = [
    "budgetcheck",
    "dutycheck",
    "snackcheck",
    "inventorycheck",
    "deskcheck",
    "maintenancecheck",
    "arbeitszeitcheck",
    "mobilitycheck",
    "projectcheck",
    "invoicecheck",
    "audiocheck",
]

sys.path.insert(0, str(ROOT / "scripts/l10n"))
from shared_quality_fixes import FORMAL_BY_LANG, IDENTICAL_BY_LANG  # noqa: E402

INFORMAL = {
    "es": re.compile(r"\b(tú|tu|te|contigo)\b", re.I),
    "da": re.compile(r"\b(du|din|dine|dit|dig)\b", re.I),
    "nb": re.compile(r"\b(du|din|dine|dit|deg)\b", re.I),
    "sv": re.compile(r"\b(du|din|dina|ditt|dig)\b", re.I),
    "nl": re.compile(r"\b(je|jij|jou|jouw)\b", re.I),
    "it": re.compile(r"\b(tu|tuo|tua|tuoi|tue|ti)\b", re.I),
    "pl": re.compile(r"\b(ty|twój|twoja|twoje|tobie|cię|ci)\b", re.I),
    "pt_BR": re.compile(r"\b(você|teu|tua|teus|tuas)\b", re.I),
}

IDENTICAL_CATALOG: dict[str, dict[str, str]] = {
    "de": {
        "agent": "Support-Mitarbeiter",
        "agent_role": "Support-Mitarbeiter",
        "category_feature": "Funktion",
        "desklet_nav_board": "Pinnwand",
        "desklet_recent_sub": "Zuletzt",
        "optional": "Freiwillig",
        "scope_role_agent": "Support-Mitarbeiter",
        "tickets": "Support-Tickets",
    },
    "da": {
        "agent": "Supportmedarbejder",
        "agent_role": "Supportmedarbejder",
        "app_access_controls": "Adgangskontrol",
        "billing": "Fakturering",
        "category_billing": "Fakturering",
        "dashboard_section_quick_actions": "Hurtige handlinger",
        "dashboard_title": "TicketCheck-kontrolpanel",
        "desklet_nav_home": "Start",
        "desklet_recent_sub": "Seneste",
        "download": "Hent",
        "download_file": "Hent fil",
        "download_tooltip": "Hent fil",
        "export_assigned_to_search_placeholder": "Søg efter navn eller brugernavn…",
        "export_col_project_customer_id": "Kunde-ID",
        "export_col_project_customer_name": "Kundenavn",
        "export_col_ticket_customer_id": "Kunde-ID",
        "export_col_ticket_customer_name": "Kundenavn",
        "export_download_csv": "Hent CSV",
        "export_section_summary": "Oversigt",
        "grant_access": "Giv adgang",
        "grant_guest_access": "Giv gæsteadgang",
        "guest_welcome_email_portal_url": "Portal-URL",
        "helpdesk_portal": "Helpdesk-portal",
        "logout": "Log ud",
        "menu": "Menukort",
        "my_support_dashboard": "Mit support-dashboard",
        "nav_group_administration": "Administrationsområde",
        "needs_role_title": "Endnu ikke tilmeldt",
        "not_authenticated": "Ikke godkendt",
        "or_drag_drop_here": "eller træk og slip her",
        "scope_role_agent": "Supportmedarbejder",
        "tickets": "Supporthenvendelser",
    },
    "nl": {
        "account": "Gebruikersaccount",
        "agent": "Supportmedewerker",
        "agent_role": "Supportmedewerker",
        "contact_email_placeholder": "contact@bedrijf.nl",
        "dashboard_section_quick_actions": "Snelle acties",
        "dashboard_title": "TicketCheck-dashboard",
        "desklet_recent_sub": "Recent",
        "desklet_recent_sub_first": "Meest recent",
        "digest_open": "Openen",
        "email_pref_guest_ticket_updated": "Gastticket bijgewerkt",
        "email_test_updates": "Testupdates",
        "export_assigned_to_search_placeholder": "Zoeken op naam of gebruikersnaam…",
        "export_col_project_customer_id": "Klant-ID",
        "export_col_project_customer_name": "Klantnaam",
        "export_col_ticket_customer_id": "Klant-ID",
        "export_col_ticket_customer_name": "Klantnaam",
        "export_filters_panel_title": "Exportfilters",
        "export_open_export_page": "Exportpagina openen",
        "guest_email_placeholder": "jan.jansen@bedrijf.nl",
        "logout": "Uitloggen",
        "menu": "Menukaart",
        "nav_group_account": "Gebruikersaccount",
        "nav_group_help": "Hulp",
        "needs_role_title": "Nog niet geregistreerd",
        "not_authenticated": "Niet geauthenticeerd",
        "open": "Openen",
        "or_drag_drop_here": "of sleep hierheen",
        "portal_home": "Portal start",
        "pro_tip": "Tip",
        "project": "Projectomgeving",
        "Project": "Projectomgeving",
        "scope_role_agent": "Supportmedewerker",
        "tickets": "Supporttickets",
    },
    "it": {
        "account": "Profilo",
        "billing": "Fatturazione",
        "category_billing": "Fatturazione",
        "contact_email_placeholder": "contatto@azienda.it",
        "dashboard_section_quick_actions": "Azioni rapide",
        "dashboard_title": "Pannello TicketCheck",
        "drag_to_reorder_tooltip": "Trascinare per riordinare",
        "export_assigned_to_search_placeholder": "Cerca per nome o utente…",
        "export_col_project_customer_id": "ID cliente",
        "export_col_project_customer_name": "Nome cliente",
        "export_col_ticket_customer_id": "ID cliente",
        "export_col_ticket_customer_name": "Nome cliente",
        "grant_guest_access": "Concedi accesso ospite",
        "guest_email_placeholder": "mario.rossi@azienda.it",
        "guest_welcome_email_password": "Parola d'accesso",
        "guest_welcome_email_portal_url": "URL del portale",
        "logout": "Disconnettersi",
        "menu": "Menù",
        "merge": "Unisci",
        "nav_group_account": "Profilo",
        "nav_group_directory": "Elenco",
        "needs_role_title": "Non ancora registrato",
        "not_authenticated": "Non autenticato",
        "or_drag_drop_here": "oppure trascinare qui",
        "portal_home": "Home portale",
        "pro_tip": "Suggerimento",
        "project_form_member_search_placeholder": "Cerca per nome…",
        "project_status_label": "Stato del progetto",
        "redirect_page_title": "Reindirizzamento",
        "request_failed": "Richiesta non riuscita",
        "scope_role_agent": "Agente di supporto",
        "tickets": "Ticket di supporto",
    },
    "pl": {
        "agent": "Agent wsparcia",
        "agent_role": "Agent wsparcia",
        "billing": "Rozliczenia",
        "category_billing": "Rozliczenia",
        "contact_email_placeholder": "kontakt@firma.pl",
        "dashboard_section_quick_actions": "Szybkie działania",
        "dashboard_title": "Panel TicketCheck",
        "export_assigned_to_search_placeholder": "Szukaj po nazwie lub użytkowniku…",
        "export_col_project_customer_id": "ID klienta",
        "export_col_project_customer_name": "Nazwa klienta",
        "export_col_ticket_customer_id": "ID klienta",
        "export_col_ticket_customer_name": "Nazwa klienta",
        "logout": "Wyloguj",
        "menu": "Menu główne",
        "needs_role_title": "Jeszcze nie zarejestrowano",
        "not_authenticated": "Nie uwierzytelniono",
        "or_drag_drop_here": "lub przeciągnij i upuść tutaj",
        "scope_role_agent": "Agent wsparcia",
        "tickets": "Zgłoszenia wsparcia",
    },
    "sv": {
        "agent": "Supportagent",
        "agent_role": "Supportagent",
        "app_access_controls": "Åtkomstkontroller",
        "avg_resolution": "Genomsnittlig lösning",
        "avg_resolution_days": "Genomsnittliga lösningsdagar",
        "avg_resolution_time": "Genomsnittlig lösningstid",
        "billing": "Fakturering",
        "billing_question": "Faktureringsfråga",
        "billing_question_option": "Faktureringsfråga",
        "budget_tracking": "Budgetuppföljning",
        "bug_report": "Felrapport",
        "bug_report_option": "Felrapport",
        "category_billing": "Fakturering",
        "category_billing_desc": "Faktureringsfråga",
        "category_bug_desc": "Felrapport",
        "dashboard_section_quick_actions": "Snabbåtgärder",
        "dashboard_title": "TicketCheck-instrumentpanel",
        "desklet_recent_sub": "Senaste",
        "desklet_untitled": "Utan titel",
        "discard_draft_button": "Kasta utkast",
        "draft": "Utkast",
        "edit_ticket": "Redigera ärende",
        "email_pref_weekly_digest": "Veckosammanfattning",
        "email_prefs_digest_section": "Sammanfattningsmejl",
        "email_project_added_title": "Tillagd i projekt",
        "export_assigned_to_search_placeholder": "Sök efter namn eller användarnamn…",
        "export_col_project_customer_id": "Kund-ID",
        "export_col_project_customer_name": "Kundnamn",
        "export_col_ticket_attachment_count": "Antal bilagor",
        "export_col_ticket_customer_id": "Kund-ID",
        "export_col_ticket_customer_name": "Kundnamn",
        "grant_guest_access": "Bevilja gäståtkomst",
        "helpdesk_portal": "Helpdesk-portal",
        "logout": "Logga ut",
        "menu": "Meny",
        "nav_group_administration": "Administratörsvy",
        "needs_role_title": "Inte registrerad än",
        "not_authenticated": "Inte autentiserad",
        "or_drag_drop_here": "eller dra och släpp här",
        "scope_role_agent": "Supportagent",
        "tickets": "Supportärenden",
    },
    "nb": {
        "agent": "Supportmedarbeider",
        "agent_role": "Supportmedarbeider",
        "billing": "Fakturering",
        "category_billing": "Fakturering",
        "dashboard_section_quick_actions": "Hurtighandlinger",
        "dashboard_title": "TicketCheck-kontrollpanel",
        "desklet_nav_board": "Tavle",
        "desklet_recent_sub": "Nylig",
        "export_assigned_to_search_placeholder": "Søk etter navn eller brukernavn…",
        "export_col_project_customer_id": "Kunde-ID",
        "export_col_project_customer_name": "Kundenavn",
        "export_col_ticket_customer_id": "Kunde-ID",
        "export_col_ticket_customer_name": "Kundenavn",
        "export_filter_results_count": "Antall treff",
        "export_multi_results_count": "Antall resultater",
        "grant_access": "Gi tilgang",
        "guest_accounts": "Gjestekontoer",
        "guest_portal_subtitle": "Gjesteportal",
        "guest_user_not_found": "Gjestebruker ikke funnet",
        "guest_welcome_email_portal_url": "Portal-URL",
        "helpdesk_portal": "Helpdesk-portal",
        "logout": "Logg ut",
        "nav_group_recovery": "Gjenoppretting",
        "needs_role_title": "Ikke registrert ennå",
        "not_authenticated": "Ikke autentisert",
        "or_drag_drop_here": "eller dra og slipp her",
        "performance_analytics": "Ytelsesanalyse",
        "portal_access": "Portaltilgang",
        "priority_urgent": "Haster",
        "project_form_member_search_placeholder": "Søk etter navn…",
        "scope_role_agent": "Supportmedarbeider",
        "tickets": "Supporthenvendelser",
    },
}

MANUAL_FIXES: dict[str, dict[str, str]] = {
    "da": {
        "You": "Egen bruger",
        "pro_tip": "Eksperttip",
        "project_form_member_search_placeholder": "Søg efter navn…",
        "project_status_label": "Projektstatus",
        "request_failed": "Anmodning mislykkedes",
        "revoke_guest_access": "Tilbagekald adgang",
        "sla_settings": "SLA-svartider",
        "support_dashboard": "Support-dashboard",
        "unknown_action": "Ukendt handling",
        "updated": "Opdateret",
        "user_id_placeholder": "Søg efter navn eller login…",
        "user_not_found": "Bruger ikke fundet.",
        "watchers": "Observatører (CC)",
    },
    "nl": {
        "You": "Eigen gebruiker",
        "project_count": "projectomgeving",
        "project_form_member_search_placeholder": "Zoeken op naam…",
        "project_label": "Projectomgeving",
        "project_lowercase": "projectomgeving",
        "project_status_label": "Projectstatus",
        "request_failed": "Verzoek mislukt",
        "scope_project_label": "Projectomgeving: {name}",
        "status_open": "Openstaand",
        "support_email_placeholder": "support@voorbeeld.nl",
        "ticket": "supportticket",
        "ticket_hash": "Ticket nr.",
        "ticket_number": "Ticket nr. %s",
        "unknown_action": "Onbekende actie",
        "updated": "Bijgewerkt",
        "user_id_placeholder": "Zoeken op naam of login…",
        "user_not_found": "Gebruiker niet gevonden.",
    },
    "it": {
        "You": "Utente proprio",
        "Escalation": "Escalazione",
        "account_deletion_section_desc": "Un amministratore esaminerà la richiesta. I ticket e i dati restano fino alla conferma dell'eliminazione.",
        "create_guest_accounts_portal_access": "Creare account ospite per consentire ai clienti l'accesso al portale di supporto.",
        "customers_empty_description": "I clienti sono le organizzazioni o le persone a cui viene fornito supporto. Creare il primo cliente per organizzare le relazioni con i clienti.",
        "desklet_nav_mine_sub": "Assegnazioni",
        "guest_password_reset_email_login_details": "Dati di accesso aggiornati:",
        "guest_welcome_email_login_details": "Dati di accesso:",
        "guest_welcome_email_subject": "Benvenuto nel portale TicketCheck — dati di accesso",
        "organize_tickets_by_service": "Organizzare i ticket per servizio o prodotto. I progetti aiutano a gestire separatamente le diverse aree del supporto clienti.",
        "privacy_notice_text": "Raccogliamo e trattiamo i dati personali (nome, e-mail, ticket di supporto) per fornire assistenza clienti. I dati sono archiviati in modo sicuro e usati solo per TicketCheck. È possibile richiedere l'eliminazione dell'account e di tutti i dati associati in qualsiasi momento.",
        "share_thoughts_placeholder": "Condivida un commento o ponga una domanda…",
        "sla_compliance": "Conformità SLA",
        "support_email_placeholder": "support@esempio.it",
        "ticket": "ticket di supporto",
        "ticket_number": "Ticket n. %s",
        "unknown_action": "Azione sconosciuta",
        "updated": "Aggiornato",
        "user_id_placeholder": "Cerca per nome o login…",
        "user_not_found": "Utente non trovato.",
    },
    "pl": {
        "You": "Użytkownik",
        "desklet_recent_sub": "Stan: %1$s",
        "digest_daily_title": "Codzienne podsumowanie TicketCheck",
        "guest_welcome_email_portal_url": "Adres URL portalu",
        "helpdesk_portal": "Portal TicketCheck",
        "newest": "Najnowsze",
        "pro_tip": "Wskazówka",
        "project_form_member_search_placeholder": "Szukaj po nazwie…",
        "project_status_label": "Status projektu",
        "request_failed": "Żądanie nie powiodło się",
        "secure_limited": "Bezpieczny i ograniczony",
        "ticket": "zgłoszenie",
        "ticket_hash": "Zgłoszenie nr",
        "unknown_action": "Nieznana czynność",
        "updated": "Zaktualizowano",
        "user_id_placeholder": "Szukaj po nazwie lub loginie…",
        "user_not_found": "Nie znaleziono użytkownika.",
    },
    "sv": {
        "You": "Egen användare",
        "grant_access": "Bevilja åtkomst",
        "guest_access": "Gäståtkomst",
        "guest_email_placeholder": "erik.andersson@foretag.se",
        "guest_welcome_email_portal_url": "Portal-URL",
        "hide_password": "Dölj lösenord",
        "invalid_token": "Ogiltig token",
        "kb_editor_bullet_list": "Punktlista",
        "kb_editor_heading_2": "Rubrik nivå 2",
        "kb_editor_heading_3": "Rubrik nivå 3",
        "key_metrics": "Nyckeltal",
        "merge": "Slå ihop",
        "nav_group_administration": "Administratörsvy",
        "nav_group_directory": "Katalog",
        "on_time_resolution": "Lösning i tid",
        "portal_access": "Portalåtkomst",
        "post_comment": "Publicera kommentar",
        "priority_urgent": "Brådskande",
        "pro_tip": "Experttips",
        "project_access": "Projektåtkomst",
        "project_detail_workflow_column": "Pipeline-status",
        "project_form_member_search_placeholder": "Sök efter namn…",
        "project_status_label": "Projektstatus",
        "refresh_access_preview": "Uppdatera åtkomstförhandsvisning",
        "remove_watcher": "Ta bort observatör",
        "reopen": "Öppna igen",
        "request_failed": "Begäran misslyckades",
        "reset_guest_password": "Återställ gästlösenord",
        "satisfaction_survey_rating": "Betygsätt upplevelsen",
        "satisfaction_survey_submit": "Skicka enkät",
        "secure_limited": "Säker och begränsad",
        "sla_compliance": "SLA-efterlevnad",
        "sort_by": "Sortera efter:",
        "support_dashboard": "Supportpanel",
        "unassigned": "Otilldelad",
        "unknown_action": "Okänd åtgärd",
        "updated": "Uppdaterad",
        "urgent": "Brådskande",
        "urgent_priority": "Brådskande",
        "user_id_placeholder": "Sök efter namn eller inloggning…",
        "user_not_found": "Användaren hittades inte.",
    },
    "nb": {
        "You": "Egen bruker",
        "project_performance_analytics": "Prosjektytelsesanalyse",
        "project_status_label": "Prosjektstatus",
        "request_failed": "Forespørsel mislyktes",
        "scope_role_guest_portal": "Gjesteportal",
        "sla_compliance": "SLA-overholdelse",
        "sla_settings": "SLA-responstider",
        "support_email_placeholder": "support@eksempel.no",
        "ticket_hash": "Sak nr.",
        "unknown_action": "Ukjent handling",
        "updated": "Oppdatert",
        "urgent": "Haster",
        "urgent_priority": "Haster",
        "user_id_placeholder": "Søk etter navn eller innlogging…",
        "user_not_found": "Bruker ikke funnet.",
        "watcher_search_placeholder": "Søk observatør…",
    },
}


def _apply_subs(text: str, subs: list[tuple[str, str]]) -> str:
    out = text
    for pat, rep in subs:
        out = re.sub(pat, rep, out, flags=re.I)
    out = re.sub(r"\s{2,}", " ", out)
    out = re.sub(r"\s+([,.;:!?])", r"\1", out)
    return out.strip()


def formalize_da(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bSpørg din\b", "Kontakt"),
            (r"\bBed din\b", "Kontakt"),
            (r"\bBed en\b", "Kontakt en"),
            (r"\bSpørg en\b", "Kontakt en"),
            (r"\bpå dine vegne\b", "på vegne af modtageren"),
            (r"\bpå din vegne\b", "på vegne af modtageren"),
            (r"\bmod dig\b", "mod modtageren"),
            (r"\btil dig\b", "til modtageren"),
            (r"\bfor dig\b", "for modtageren"),
            (r"\bHvis du\b", "Hvis"),
            (r"\bhvis du\b", "hvis"),
            (r"\bNår du\b", "Når"),
            (r"\bnår du\b", "når"),
            (r"\bDu kan\b", "Der kan"),
            (r"\bdu kan\b", "der kan"),
            (r"\bDu skal\b", "Der skal"),
            (r"\bdu skal\b", "der skal"),
            (r"\bDu er\b", "Der er"),
            (r"\bdu er\b", "der er"),
            (r"\bDu har\b", "Der er"),
            (r"\bdu har\b", "der er"),
            (r"\bdu ikke\b", "der ikke"),
            (r"\bEr du\b", "Er"),
            (r"\ber du\b", "er"),
            (r"\bdin organisation\b", "organisationen"),
            (r"\bdine filtre\b", "filtrene"),
            (r"\bdine\b", "de relevante"),
            (r"\bdin\b", "den aktuelle"),
            (r"\bdit\b", "det aktuelle"),
            (r"\bdig\b", ""),
            (r"\bdu\b", ""),
        ],
    )


def formalize_sv(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bFråga din\b", "Kontakta"),
            (r"\bBe din\b", "Kontakta"),
            (r"\bpå dina vägar\b", "på mottagarens vägar"),
            (r"\bpå dina vägnar\b", "på mottagarens vägnar"),
            (r"\bmot dig\b", "mot mottagaren"),
            (r"\btill dig\b", "till mottagaren"),
            (r"\bför dig\b", "för mottagaren"),
            (r"\bOm du\b", "Om"),
            (r"\bom du\b", "om"),
            (r"\bNär du\b", "När"),
            (r"\bnär du\b", "när"),
            (r"\bDu kan\b", "Det går att"),
            (r"\bdu kan\b", "det går att"),
            (r"\bDu har\b", "Det finns"),
            (r"\bdu har\b", "det finns"),
            (r"\bEr du\b", "Är"),
            (r"\ber du\b", "är"),
            (r"\bdina filter\b", "filtren"),
            (r"\bdina\b", "de relevanta"),
            (r"\bdin\b", "aktuella"),
            (r"\bditt\b", "aktuella"),
            (r"\bdig\b", ""),
            (r"\bdu\b", ""),
        ],
    )


def formalize_nb(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bSpør din\b", "Kontakt"),
            (r"\bBe din\b", "Kontakt"),
            (r"\bpå dine vegne\b", "på mottakerens vegne"),
            (r"\bmot deg\b", "mot mottakeren"),
            (r"\btil deg\b", "til mottakeren"),
            (r"\bfor deg\b", "for mottakeren"),
            (r"\bHvis du\b", "Hvis"),
            (r"\bhvis du\b", "hvis"),
            (r"\bDu kan\b", "Det kan"),
            (r"\bdu kan\b", "det kan"),
            (r"\bDu har\b", "Det finnes"),
            (r"\bdu har\b", "det finnes"),
            (r"\bEr du\b", "Er"),
            (r"\ber du\b", "er"),
            (r"\bdine\b", "de relevante"),
            (r"\bdin\b", "gjeldende"),
            (r"\bditt\b", "gjeldende"),
            (r"\bdeg\b", ""),
            (r"\bdu\b", ""),
        ],
    )


def formalize_nl(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bje\b", "u"),
            (r"\bJe\b", "U"),
            (r"\bjij\b", "u"),
            (r"\bjou\b", "u"),
            (r"\bjouw\b", "uw"),
        ],
    )


def formalize_it(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bil tuo\b", "il proprio"),
            (r"\btua prenotazione\b", "prenotazione"),
            (r"\btue prossime\b", "prossime"),
            (r"\btuo ambito\b", "ambito"),
            (r"\bche tu possa\b", "che sia possibile"),
            (r"\btu\b", ""),
            (r"\btuo\b", "proprio"),
            (r"\btua\b", "propria"),
            (r"\btue\b", "proprie"),
            (r"\bti\b", ""),
        ],
    )


def formalize_pl(text: str) -> str:
    return _apply_subs(
        text,
        [
            (r"\bTwój\b", "Przypisany"),
            (r"\bTwoja\b", "Przypisana"),
            (r"\bTwoje\b", "Przypisane"),
            (r"\bTwoj\b", "Przypisany"),
            (r"\bCię\b", ""),
            (r"\bcię\b", ""),
            (r"\bCi\b", ""),
            (r"\bci\b", ""),
            (r"\bTobie\b", ""),
            (r"\btobie\b", ""),
            (r"\bTy\b", ""),
            (r"\bty\b", ""),
        ],
    )


FORMALIZERS = {
    "da": formalize_da,
    "sv": formalize_sv,
    "nb": formalize_nb,
    "nl": formalize_nl,
    "it": formalize_it,
    "pl": formalize_pl,
}


def extract_gaps() -> dict:
    proc = subprocess.run(
        ["php", str(ROOT / "scripts/l10n/extract-formal-gaps.php"), "--app=ticketcheck", "--json"],
        capture_output=True,
        text=True,
        check=True,
        cwd=ROOT,
    )
    return json.loads(proc.stdout)


def load_trans(app: str, lang: str) -> dict[str, str]:
    path = APPS / app / "l10n" / f"{lang}.json"
    if not path.is_file():
        return {}
    return json.loads(path.read_text(encoding="utf-8")).get("translations", {})


def load_qf(app: str, lang: str) -> dict[str, str]:
    path = APPS / app / "l10n" / f"_quality_fixes_{lang}.json"
    if not path.is_file():
        return {}
    data = json.loads(path.read_text(encoding="utf-8"))
    return data if isinstance(data, dict) else {}


def is_good(val: object, key: str, lang: str, en: dict[str, str]) -> bool:
    if not isinstance(val, str) or not val:
        return False
    if val == en.get(key, key):
        return False
    if lang in INFORMAL and INFORMAL[lang].search(val):
        return False
    return True


def seeds(lang: str, en: dict[str, str]) -> dict[str, str]:
    out: dict[str, str] = {}
    for app in SEED_APPS:
        for src in (load_qf(app, lang), load_trans(app, lang)):
            for key, val in src.items():
                if key not in out and is_good(val, key, lang, en):
                    out[key] = val
    for src in (IDENTICAL_BY_LANG.get(lang, {}), FORMAL_BY_LANG.get(lang, {})):
        for key, val in src.items():
            if val:
                out[key] = val
    return out


def build_fixes(gaps: dict, en: dict[str, str]) -> dict[str, dict[str, str]]:
    trans = {
        lang: json.loads((L10N / f"{lang}.json").read_text(encoding="utf-8"))["translations"]
        for lang in LOCALES
        if (L10N / f"{lang}.json").is_file()
    }
    seed = {lang: seeds(lang, en) for lang in LOCALES}
    fixes: dict[str, dict[str, str]] = {}

    for lang in LOCALES:
        gap = gaps.get(lang, {})
        keys = set(gap.get("identical", {})) | set(gap.get("informal", {}))
        if not keys:
            continue
        lang_fixes: dict[str, str] = {}
        missing: list[str] = []

        for key in sorted(keys):
            if key in MANUAL_FIXES.get(lang, {}):
                lang_fixes[key] = MANUAL_FIXES[lang][key]
            elif key in IDENTICAL_CATALOG.get(lang, {}):
                lang_fixes[key] = IDENTICAL_CATALOG[lang][key]
            elif key in seed[lang]:
                lang_fixes[key] = seed[lang][key]
            elif key in gap.get("informal", {}) and lang in FORMALIZERS:
                candidate = FORMALIZERS[lang](trans[lang].get(key, ""))
                if is_good(candidate, key, lang, en):
                    lang_fixes[key] = candidate
                else:
                    missing.append(key)
            else:
                missing.append(key)

        if missing:
            print(f"{lang}: WARNING missing {len(missing)} keys", file=sys.stderr)
            (L10N / f"_missing_after_build_{lang}.json").write_text(
                json.dumps(missing, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
        fixes[lang] = lang_fixes

    return fixes


def main() -> None:
    gaps = extract_gaps()
    en = json.loads((L10N / "en.json").read_text(encoding="utf-8"))["translations"]
    fixes = build_fixes(gaps, en)

    for lang, lang_fixes in fixes.items():
        out = L10N / f"_quality_fixes_{lang}.json"
        out.write_text(json.dumps(lang_fixes, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8")
        print(f"{lang}: {len(lang_fixes)} fixes")


if __name__ == "__main__":
    main()
