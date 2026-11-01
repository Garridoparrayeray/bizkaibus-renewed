<?php

namespace Services;

/*
 * Las novedades de cada versión de la app, de la más nueva a la más vieja: [fecha ISO, fecha, número, título, texto],
 * en castellano. El número de la primera es la versión de la app: lo enseña Ajustes y, cuando sube, se avisa de la
 * versión nueva (UpdateAlerts y js/app-update.js). El euskera y el inglés están en js/i18n.js (bide.changelog.N.title y
 * .text); el aviso los toma de ahí, porque el móvil con la versión vieja todavía no tiene esos textos.
 */
final class Changelog
{
    public const ENTRIES = [
        ['2026-11-01', '01/11/2026', 34, 'Aviso de actualizaciones', 'Cuando sale una versión nueva de Bide+, te llega una notificación (si las tienes permitidas) y la rueda de Ajustes enseña un 1 en rojo. En Ajustes ves lo que trae y la instalas con «Vaciar caché y actualizar». Además, en la web el pie se queda al abrir Ajustes, Info, Avisos o Favoritos.'],
        ['2026-11-01', '01/11/2026', 33, 'Hora estimada más afinada', 'Cuando no hay datos del bus, la hora estimada de Bizkaibus tiene en cuenta también lo que suele retrasarse cada viaje al principio, a la mitad y al final de su recorrido, aprendido de los pasos reales de los últimos días. Acierta más: el error medio baja de 3,5 a 2,6 minutos. Vale con conexión y en los horarios guardados para usar sin conexión.'],
        ['2026-10-31', '31/10/2026', 32, 'Horario oficial al día', 'En «Ver horario oficial» de Bizkaibus salen el periodo de hoy y los que vienen, con los días sueltos de un mismo periodo juntos en una sola tarjeta (por ejemplo, el 24 y el 31 de diciembre). Lo que ya terminó no sale, y al cambiar de año desaparece lo del anterior. El botón ya no lleva el año.'],
        ['2026-10-31', '31/10/2026', 31, 'Avisos que se quitan solos', 'En todas las apps, un «Avísame cuando llegue» avisa una vez y desaparece de la app en cuanto llega la notificación. Si marcas «Avisarme todos los días», te avisa cada día que haya ese viaje hasta que lo canceles en la ficha o en Ajustes. Al tocar la notificación se abre la parada con el viaje, también en iPhone con la app cerrada, y al volver a abrir la app ya no te lleva otra vez a ese viaje. Las notificaciones de incidencias, al tocarlas, abren la pestaña Avisos de su app. En Android, el icono pequeño de la barra de estado es ahora el de Bide+.'],
        ['2026-10-31', '31/10/2026', 30, 'Sin conexión, como con conexión', 'En Ajustes puedes guardar todas las redes de una vez. Sin cobertura, Bizkaibus enseña la hora estimada con lo que tardan de verdad los buses y, debajo, la oficial; y en cualquier red puedes marcar si un bus ya pasó o no. Con conexión, las horas estimadas también llevan debajo la oficial. El aviso de sin conexión es ahora una pastilla pequeña abajo. Y en Favoritos, Avisos, Ajustes e Info el título se queda fijo arriba al bajar.'],
        ['2026-10-31', '31/10/2026', 29, 'Llegadas más precisas', 'Cuando en un tramo hay pocos buses medidos a una hora, lo que tardan a esa hora se completa con lo que tardan a cualquier hora. Las llegadas aciertan algo más, sobre todo las que faltan de 5 a 20 minutos. Y en Info, la pregunta de cómo instalar Bide+ dice desde qué navegador: Chrome o Firefox en Android y Safari en iPhone.'],
        ['2026-10-31', '31/10/2026', 28, 'Versión, caché y ubicación', 'En Ajustes ves la versión de la app, puedes vaciar la caché si algo no carga y ver y dar el permiso de ubicación para «Cerca de mí».'],
        ['2026-10-31', '31/10/2026', 27, 'Ajustes', 'Una pestaña nueva con el idioma, las notificaciones (las incidencias de tus favoritos y los avisos de llegada que tengas activos, que puedes cancelar), los horarios sin conexión y un botón para borrar tus datos del móvil.'],
        ['2026-10-30', '30/10/2026', 26, 'Incidencias con la app cerrada', 'Los avisos de incidencias de tus favoritos llegan aunque no abras la app: los manda el servidor cada pocos minutos, de todas las apps a la vez. Si ya los tenías activados, pasan a funcionar así sin hacer nada.'],
        ['2026-10-30', '30/10/2026', 25, 'Avísame cuando llegue', 'En la ficha de un viaje elige 2, 5 o 10 minutos y el móvil te avisa antes de que llegue a tu parada, aunque tengas la app cerrada. En iPhone, con Bide+ añadida a la pantalla de inicio.'],
        ['2026-10-30', '30/10/2026', 24, 'Bide+ en inglés', 'Toda la app, también en inglés. Si tu móvil está en inglés, Bide+ se abre en inglés; puedes cambiar de idioma cuando quieras.'],
        ['2026-10-30', '30/10/2026', 23, 'Pantalla de parada', 'Cada parada tiene su pantalla a tamaño completo con las próximas salidas y la hora, que se actualiza sola. Pensada para una tele o una tablet en un bar, un portal o una tienda.'],
        ['2026-10-30', '30/10/2026', 22, 'Comparte tu viaje', 'Desde la ficha de un viaje, manda un enlace a quien quieras: lo abre en directo y ve por dónde va tu bus o tu tren y cuándo llega.'],
        ['2026-10-30', '30/10/2026', 21, 'Horarios sin conexión', 'Desde Info puedes guardar en el móvil el horario oficial de las redes que uses. Sin cobertura, cada parada sigue mostrando sus próximas salidas. Además, preguntas frecuentes y un apartado para mandar sugerencias o avisar de errores.'],
        ['2026-10-30', '30/10/2026', 20, 'Mapa de la red', 'Todas las líneas de las siete redes sobre el mapa, con el esquema de cada una, los minutos entre paradas y los transbordos. Puedes buscar cualquier línea o parada y encender cada red por separado.'],
        ['2026-10-30', '30/10/2026', 19, 'Autobuses sin GPS', 'Cuando un autobús no envía su posición, su llegada sale como «Estimado» y se calcula con lo que tardan de verdad los buses. La ficha del autobús da el mismo adelanto o retraso que la parada desde la que la abres.'],
        ['2026-10-30', '30/10/2026', 18, 'Renfe más fiable', 'Todas las estaciones de Renfe se encuentran, también las que empiezan por cero, y ya salen los horarios de Vado-Cervera.'],
        ['2026-10-29', '29/10/2026', 17, 'Horarios siempre del día', 'Los horarios de todas las redes se renuevan cada día con los datos oficiales, y en el horario de cada línea ves desde y hasta cuándo es válido.'],
        ['2026-10-27', '27/10/2026', 16, 'Lurraldebus+', 'Llega Lurraldebus+, con los autobuses de Gipuzkoa de siete operadores y la dirección de cada parada.'],
        ['2026-10-27', '27/10/2026', 15, 'Buscador más listo', 'El buscador encuentra la parada aunque escribas el pueblo y la calle en cualquier orden.'],
        ['2026-10-26', '26/10/2026', 14, 'Más fácil de encontrar', 'Cada parada y cada línea tiene su propia página, para llegar a ella directamente desde Google.'],
        ['2026-10-16', '16/10/2026', 13, 'Viajes más exactos', 'Cada autobús de Bizkaibus usa el calendario exacto de su día, y la ficha del viaje ya no da por salido un autobús del que no hay datos en vivo.'],
        ['2026-10-14', '14/10/2026', 12, 'Retrasos más claros', 'El retraso se escribe entero junto a los minutos: «Lleva 5 min de retraso» o «Va 2 min adelantado». En Bizkaibus+, sin GPS se usa la última parada por la que pasó el autobús, y en Metro+ el recorrido del tren avanza con el reloj.'],
        ['2026-10-06', '06/10/2026', 11, 'Llegadas de Bizkaibus más precisas', 'Algunos buses usaban las horas de paso de otro tipo de día (por ejemplo, las de festivo en un laborable) y la app los daba por pasados antes de tiempo. Ahora cada viaje lleva sus horas oficiales, y en tramos como la autopista hacia Gernika la llegada se calcula con lo que tardan de verdad los buses.'],
        ['2026-10-05', '05/10/2026', 1, 'Metro+ en directo', 'Los próximos trenes de Metro Bilbao llegan en directo y con su retraso, gracias al Consorcio de Transportes de Bizkaia. Arreglados los tranvías que desaparecían algunos días.'],
        ['2026-10-04', '04/10/2026', 2, 'Más fácil de usar', 'El botón atrás del móvil funciona bien, la pestaña activa se distingue mejor y los colores cumplen las pautas de accesibilidad WCAG 2.1 AA.'],
        ['2026-10-02', '02/10/2026', 3, 'Bide+, una sola app', 'Todas las apps dentro de Bide+: pestañas en el móvil, columna lateral en el ordenador y páginas de Favoritos, Avisos e Info. Más seguridad y mejor posición en los buscadores.'],
        ['2026-10-01', '01/10/2026', 4, 'Tranvías, Renfe y bus por GPS', 'Llegan Tranvía Bilbao+, Tranvía Vitoria+ y Renfe Cercanías+. En Bizkaibus+, la llegada se calcula con la posición GPS del autobús sobre su ruta.'],
        ['2026-09-22', '22/09/2026', 5, 'Nace Bide+', 'Bide+ reúne las apps de transporte de Euskadi. Llegan «Cerca de mí», los avisos de incidencias de tus líneas favoritas y el uso sin conexión.'],
        ['2026-09-16', '16/09/2026', 6, 'Euskotren+', 'Horarios de Euskotren. Además, diseño nuevo para ordenador, enlace propio para cada parada y línea, y mejoras para iPhone.'],
        ['2026-09-08', '08/09/2026', 7, 'Instalable como app', 'La app se puede instalar en el móvil, con accesos directos a Bizkaibus+ y Metro+.'],
        ['2026-08-18', '18/08/2026', 8, 'Metro+', 'Segunda red: horarios de Metro Bilbao, panel de andén por sentido y avisos de incidencias.'],
        ['2026-07-27', '27/07/2026', 9, 'Horarios siempre al día', 'Los horarios se actualizan solos cada día desde los datos oficiales, y las salidas con retraso ya no desaparecen.'],
        ['2026-07-16', '16/07/2026', 10, 'Primera versión (v0.8)', 'Nace BizkaiBus+: los horarios de Bizkaibus más fáciles de consultar, con buscador, favoritos y el tema «mi amor».'],

    ];

    private const I18N_FILE = __DIR__ . '/../../js/i18n.js';
    private const MAX_NOTES = 10;

    public static function version(): int
    {
        return self::ENTRIES[0][2];
    }

    // Las novedades posteriores a la versión $iVersion, de la más nueva a la más vieja, en el idioma $sLang (lo que no
    // esté traducido, en castellano).
    public static function newerThan(int $iVersion, string $sLang): array
    {
        $aTexts = self::translations($sLang);
        $aNotes = [];
        foreach (self::ENTRIES as [$sIsoDate, , $iNumber, $sTitle, $sText]) {
            if ($iNumber <= $iVersion) {
                continue;
            }
            $aNotes[] = [
                'number' => $iNumber,
                'date' => $sIsoDate,
                'title' => $aTexts[$iNumber . '.title'] ?? $sTitle,
                'text' => $aTexts[$iNumber . '.text'] ?? $sText,
            ];
        }
        return array_slice($aNotes, 0, self::MAX_NOTES);
    }

    // Los títulos y textos de las novedades en euskera o en inglés, sacados de su bloque de js/i18n.js.
    private static function translations(string $sLang): array
    {
        static $aCache = [];
        if ($sLang === 'es' || !is_file(self::I18N_FILE)) {
            return [];
        }
        if (!isset($aCache[$sLang])) {
            $aCache[$sLang] = [];
            $sI18n = (string)file_get_contents(self::I18N_FILE);
            if (preg_match('/^ {8}' . preg_quote($sLang, '/') . ': \\{$(.*?)^ {8}\\},?$/ms', $sI18n, $aBlock) === 1) {
                preg_match_all("/^ {12}'bide\\.changelog\\.(\\d+)\\.(title|text)': '((?:[^'\\\\]|\\\\.)*)',$/m", $aBlock[1], $aMatches, PREG_SET_ORDER);
                foreach ($aMatches as [, $sNumber, $sField, $sValue]) {
                    $aCache[$sLang][$sNumber . '.' . $sField] = stripslashes($sValue);
                }
            }
        }
        return $aCache[$sLang];
    }
}
