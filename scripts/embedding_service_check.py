"""Read-only embedding API checks using synthetic image and text probes."""
import base64
import json
import math
from pathlib import Path
import re
import urllib.error
import urllib.parse
import urllib.request

# Synthetic 32x32 PNG; no user media or saved vectors are accessed.
IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAAKElEQVR4nO3NMQEAAAjDMMC/ZzDBvlRA01vZJvwHAAAAAAAAAAAAbx2jxAE/i2AjOgAAAABJRU5ErkJggg=='


class ServiceCheckError(ValueError):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def check_service(url, key='', model=None, family=None, timeout=10):
    try:
        parsed=urllib.parse.urlsplit(url)
        if parsed.port is not None and not 1<=parsed.port<=65535:raise ValueError()
    except (ValueError,TypeError):raise ServiceCheckError('address') from None
    if (parsed.scheme not in ('http','https') or not parsed.hostname or parsed.username
        or parsed.password or parsed.query or parsed.fragment):
        raise ServiceCheckError('address')
    if not isinstance(key,str) or len(key)>4096 or any(c in key for c in '\r\n\x00'):
        raise ServiceCheckError('key')
    url=url.rstrip('/')
    if not url.endswith('/v1'):url+='/v1'
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
    def call(endpoint, body=None):
        headers={'Accept':'application/json','Content-Type':'application/json'}
        if key:headers['Authorization']='Bearer '+key
        data=None if body is None else json.dumps(body).encode()
        request=urllib.request.Request(url+'/'+endpoint,data=data,headers=headers)
        try:
            with opener.open(request,timeout=timeout) as response:
                wire=response.read(1048577)
                if len(wire)>1048576:raise ServiceCheckError('response')
                value=json.loads(wire)
                if not isinstance(value,dict):raise ServiceCheckError('response')
                return value
        except urllib.error.HTTPError as error:
            if error.code in (401,403):raise ServiceCheckError('authentication') from None
            if error.code in (301,302,303,307,308):raise ServiceCheckError('redirect') from None
            raise ServiceCheckError('api') from None
        except (urllib.error.URLError,TimeoutError,OSError):raise ServiceCheckError('unreachable') from None
        except (UnicodeError,json.JSONDecodeError):raise ServiceCheckError('response') from None
    info=call('info')
    remote_family=info.get('family')
    if (not isinstance(info.get('model'),str) or not info['model'].strip()
        or not isinstance(remote_family,str) or not remote_family.strip() or len(remote_family)>128
        or any(ord(c)<32 or ord(c)==127 for c in remote_family)
        or type(info.get('dimensions')) is not int or not 128<=info['dimensions']<=4096
        or not isinstance(info.get('space'),str) or not re.fullmatch('[a-f0-9]{64}',info['space'])
        or not isinstance(info.get('modalities'),list) or not all(modality in info['modalities'] for modality in ('text','image'))):
        raise ServiceCheckError('identity')
    if (model is not None and info['model']!=model) or (family not in (None,'custom') and remote_family!=family):
        raise ServiceCheckError('model')
    for probe in ({'text':'a red square'},{'image':IMAGE}):
        result=call('embed',{'model':info['model'],**probe})
        values=result.get('embedding')
        if (result.get('space')!=info['space'] or not isinstance(values,list)
            or len(values)!=info['dimensions'] or any(type(x) not in (int,float) or not math.isfinite(x) for x in values)):
            raise ServiceCheckError('vector')
        norm=sum(x*x for x in values)
        if not math.isfinite(norm) or norm<1e-16:raise ServiceCheckError('vector')
    after=call('info')
    if any(after.get(field)!=info[field] for field in ('model','family','space','dimensions')):
        raise ServiceCheckError('changed')
    return info


MESSAGES={
 'address':('API-Adresse ungültig. http(s)://SERVER:PORT/v1 verwenden.','Invalid API address. Use http(s)://SERVER:PORT/v1.'),
 'key':('API-Key ungültig.','Invalid API key.'),
 'authentication':('Anmeldung fehlgeschlagen. API-Key prüfen.','Authentication failed. Check the API key.'),
 'redirect':('Die API leitet weiter. Direkte API-Adresse eintragen; der API-Key wird nicht weitergeleitet.','The API redirects. Enter the direct API address; the API key is not forwarded.'),
 'api':('Der Dienst antwortet, aber die benötigte API ist nicht bereit. Logs und API-Pfad prüfen.','The service responds, but the required API is not ready. Check logs and the API path.'),
 'unreachable':('Dienst nicht erreichbar oder Zeitüberschreitung. Läuft der Container? Port, Adresse und Firewall prüfen.','Service unreachable or timed out. Is the container running? Check the port, address and firewall.'),
 'response':('Ungültige oder zu große API-Antwort.','Invalid or oversized API response.'),
 'identity':('Der Dienst muss Modell, Familie, Dimension, Vektorraum sowie Bild- und Textunterstützung melden.','The service must declare its model, family, dimensions, vector space, and image/text support.'),
 'model':('Modell-ID oder Modellfamilie stimmt nicht mit dem Dienst überein.','Model ID or family does not match the service.'),
 'vector':('Bild- oder Textprobe lieferte ungültige oder inkompatible Vektoren.','The image or text probe returned invalid or incompatible vectors.'),
 'changed':('Der Dienst hat während der Prüfung seinen Modellraum geändert.','The service changed its vector space during the check.'),
}


def run_check(directory, language='en', url=None, model=None, key=None, family=None):
    index=0 if language=='de' else 1
    if url is None:
        connection_file=Path(directory)/'connection.json'
        if not connection_file.is_file():
            print(('Keine Verbindungsdaten gefunden. Dienst zuerst einrichten.','No connection details found. Configure the service first.')[index])
            return 1
        try:
            connection=json.loads(connection_file.read_text())
            if not isinstance(connection,dict):raise ValueError()
        except (OSError,ValueError):
            print(('Gespeicherte Verbindungsdaten konnten nicht gelesen werden. Konfiguration prüfen.','Saved connection details could not be read. Check the configuration.')[index])
            return 1
        port=connection.get('port',9379)
        url=f'http://127.0.0.1:{port}/v1'
        key=connection.get('api_key','')
        model=connection.get('model')
        family=connection.get('family')
    print(('Prüfe Dienst mit synthetischem Bild und Suchtext …','Checking the service with a synthetic image and search text …')[index])
    try:info=check_service(url,key or '',model,family)
    except ServiceCheckError as error:
        print(MESSAGES[str(error)][index])
        print(('Keine Einstellungen oder Vektoren geändert.','No settings or vectors changed.')[index])
        return 1
    print(('Bereit: {model} · {dimensions} Dimensionen · Bild und Text geprüft.','Ready: {model} · {dimensions} dimensions · image and text checked.')[index].format(**info))
    print(('Vektorraum: ','Vector space: ')[index]+info['space'])
    print(('Kompatibilität mit vorhandenen Vektoren anschließend in NextGallery prüfen.','Next, check compatibility with existing vectors in NextGallery.')[index])
    return 0


def custom_setup(language='en', url=None, model=None, key=None, interactive=True):
    """Guide and check a bring-your-own service without configuring a Gemma container."""
    import getpass
    index=0 if language=='de' else 1
    guide='https://github.com/wolpa29/nextcloud-nextgallery/blob/main/docs/EXTERNAL_EMBEDDING_SERVER'+('.en' if index else '')+'.md#custom-models'
    print(('Eigenes Modell: Bild- und Text-Embedding-Dienst mit der NextGallery-API bereitstellen.','Custom model: provide an image/text embedding service implementing the NextGallery API.')[index])
    print(('Anleitung und Adaptervorlage: ','Guide and adapter template: ')[index]+guide)
    if interactive:
        try:
            url=input(('API-Adresse des vorhandenen Dienstes (leer = nur Anleitung): ','Existing service API address (blank = guide only): ')[index]).strip()
            if not url:return 0
            model=input(('Exakte Modell-ID: ','Exact model ID: ')[index]).strip()
            key=getpass.getpass(('API-Key (Eingabe verborgen): ','API key (input hidden): ')[index])
        except (EOFError,KeyboardInterrupt):
            print(('Abgebrochen.','Cancelled.')[index]);return 1
    if not url or not model:
        print(('API-Adresse und Modell-ID angeben.','Provide the API address and model ID.')[index])
        return 1
    result=run_check(Path(),language,url,model,key,'custom')
    if result==0:
        print(('In NextGallery „Externer Embedding-Server“ und „Eigenes Modell“ wählen.','In NextGallery, select “External embedding server” and “Custom model”.')[index])
        print(('API-Adresse: ','API address: ')[index]+url)
        print(('Modell-ID: ','Model ID: ')[index]+model)
        print(('Denselben API-Key dort eingeben. Der Schlüssel wurde hier nicht gespeichert.','Enter the same API key there. The key was not saved here.')[index])
    return result
