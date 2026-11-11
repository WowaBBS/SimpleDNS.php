<?
include 'Binary.php';

final class TestRunner
{
  public int $pass = 0;
  public int $fail = 0;

  public function ok(bool $cond, string $name): void
  {
    if ($cond) { $this->pass++; echo "PASS  $name\n"; }
    else       { $this->fail++; echo "FAIL  $name\n"; }
  }

  public function eq(mixed $got, mixed $want, string $name): void
  {
    if ($got === $want) { $this->pass++; echo "PASS  $name\n"; }
    else
    {
      $this->fail++;
      echo "FAIL  $name\n    ожидалось: " . var_export($want, true)
         . "\n    получено:  " . var_export($got, true) . "\n";
    }
  }

  public function throws(callable $fn, string $name): void
  {
    try
    {
      $fn();
      $this->fail++;
      echo "FAIL  $name - исключение не выброшено\n";
    }
    catch (Throwable)
    {
      $this->pass++;
      echo "PASS  $name\n";
    }
  }

  public function summary(): void
  {
    printf("\nИтог: %d пройдено, %d упало\n", $this->pass, $this->fail);
    exit($this->fail > 0 ? 1 : 0);
  }
}
