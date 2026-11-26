<?
/* ============================================================
 * JsonSink - приёмник готового JSON.
 * JsonWriter владеет структурой и порядком, sink знает только,
 * как записать один элемент: контейнер, значение, запятую.
 * Реализации: массив в памяти, поток, строка - что угодно.
 * ============================================================ */

interface JsonSink
{
  /** Открыть контейнер: 'object' или 'list'; $key = null - элемент массива */
  public function open(string $kind, ?string $key): void;

  /** Закрыть последний открытый контейнер */
  public function close(string $kind): void;

  /** Запятая между элементами одного контейнера */
  public function separator(): void;

  /** Значение; $key = null - элемент массива */
  public function scalar(string|int|bool|null $value, ?string $key): void;

  /** Сбросить накопленное во внешний приёмник (файл, поток) */
  public function flush(): void;
}
