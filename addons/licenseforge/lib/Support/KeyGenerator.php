<?php

declare(strict_types=1);

namespace LicenseForge\Support;

/**
 * Generates licence keys, and judges whether a key format is strong enough.
 *
 * A licence key is a bearer credential, so the only thing between an attacker
 * and a working licence is that keys cannot be guessed. That makes the format a
 * security control rather than a presentation choice.
 *
 * Hence this class both produces keys and measures formats. A vendor may
 * configure length, segmentation and alphabet, but a combination that would not
 * be unguessable is refused at the settings screen - see {@see entropyBits()}
 * and {@see MINIMUM_ENTROPY_BITS}. A weak format is not visibly wrong; the keys
 * still look like keys.
 *
 * Keys are generated with `random_int()` throughout. A `rand()`-based generator
 * would produce keys that look identical and are predictable from a handful of
 * samples.
 *
 * The class also owns how keys are read: normalisation, masking for storage in
 * history, and the sanity checks applied to a key arriving from a client.
 */
final class KeyGenerator
{
    /**
     * Crockford base32: digits and uppercase letters, minus I, L, O and U.
     *
     * The default, because licence keys are read aloud, typed from screenshots
     * and transcribed into config files. I/1, L/1 and O/0 are the pairs
     * confused doing that, and U is excluded so the alphabet cannot spell
     * unfortunate words by accident.
     *
     * 32 characters is also a clean 5 bits each, keeping the entropy arithmetic
     * exact.
     */
    public const ALPHABET_CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    /**
     * Hexadecimal. Familiar, but only 4 bits per character - a format using it
     * needs proportionally more of them to clear the entropy floor.
     */
    public const ALPHABET_HEX       = '0123456789ABCDEF';
    /**
     * Full alphanumerics, including the ambiguous characters Crockford omits.
     * Slightly denser, at the cost of keys being harder to transcribe correctly.
     */
    public const ALPHABET_ALNUM     = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * The entropy floor a configured key format must clear.
     *
     * 80 bits puts guessing a valid key beyond reach even for an attacker
     * unconstrained by rate limits, which matters because the limits are a
     * mitigation rather than the boundary.
     *
     * The default format - four segments of four Crockford characters - carries
     * 80 bits exactly, so it sits at the floor rather than above it. Shortening
     * it in any dimension fails the check.
     */
    public const MINIMUM_ENTROPY_BITS = 80.0;

    private string $prefix;
    private int $segments;
    private int $segmentLength;
    private string $separator;
    private bool $uppercase;
    private string $alphabet;

    /**
     * @param array<string,mixed> $format Overrides for prefix, segments,
     *   segment_length, separator, uppercase and alphabet.
     *
     * Segment count and length are floored rather than validated, so a
     * nonsensical configuration still produces a key. The real guard is
     * {@see entropyBits()}, which the settings screen checks; refusing to
     * construct would move that failure somewhere less useful.
     */
    public function __construct(array $format = [])
    {
        $this->prefix        = (string) ($format['prefix'] ?? '');
        $this->segments      = max(1, (int) ($format['segments'] ?? 4));
        $this->segmentLength = max(2, (int) ($format['segment_length'] ?? 4));
        $this->separator     = (string) ($format['separator'] ?? '-');
        $this->uppercase     = (bool) ($format['uppercase'] ?? true);
        $this->alphabet      = self::resolveAlphabet((string) ($format['alphabet'] ?? 'crockford'));
    }

    /**
     * A generator configured from the module's settings.
     *
     * The prefix override is how a product supplies its own - a vendor with
     * several product lines wants each key recognisable at a glance - while
     * everything else stays global, since key strength is not something to vary
     * per product.
     */
    public static function fromSettings(?string $prefixOverride = null): self
    {
        return new self([
            'prefix'         => $prefixOverride !== null && $prefixOverride !== ''
                ? $prefixOverride
                : (string) Settings::get('key_prefix', 'LFG'),
            'segments'       => Settings::int('key_segments', 4),
            'segment_length' => Settings::int('key_segment_length', 4),
            'separator'      => (string) Settings::get('key_separator', '-'),
            'uppercase'      => Settings::bool('key_uppercase', true),
            'alphabet'       => (string) Settings::get('key_alphabet', 'crockford'),
        ]);
    }

    /**
     * The character set for a named alphabet.
     *
     * Falls back to Crockford for anything unrecognised, which is the strongest
     * of the three per character and the safest reading of a misconfigured
     * value.
     */
    private static function resolveAlphabet(string $name): string
    {
        switch (strtolower($name)) {
            case 'hex':
                return self::ALPHABET_HEX;
            case 'alnum':
                return self::ALPHABET_ALNUM;
            case 'crockford':
            default:
                return self::ALPHABET_CROCKFORD;
        }
    }

    /**
     * How many bits of randomness this format produces.
     *
     * Counts only the random part. The prefix and separators are structure, not
     * randomness - identical across every key - so including them would overstate
     * a format's strength by exactly the amount an attacker already knows.
     */
    public function entropyBits(): float
    {
        return $this->segments * $this->segmentLength * log(strlen($this->alphabet), 2);
    }

    /**
     * Produce one key in the configured format.
     *
     * Every character is drawn with `random_int()`, which is cryptographically
     * secure and unbiased across the alphabet. Modulo-reducing a weaker source
     * would both skew the distribution and make the sequence predictable.
     *
     * No uniqueness check happens here - see {@see generateUnique()}.
     */
    public function generate(): string
    {
        $parts = [];
        if ($this->prefix !== '') {
            $parts[] = $this->uppercase ? strtoupper($this->prefix) : $this->prefix;
        }

        $max = strlen($this->alphabet) - 1;
        for ($s = 0; $s < $this->segments; $s++) {
            $segment = '';
            for ($c = 0; $c < $this->segmentLength; $c++) {
                $segment .= $this->alphabet[random_int(0, $max)];
            }
            $parts[] = $segment;
        }

        $key = implode($this->separator, $parts);

        return $this->uppercase ? strtoupper($key) : strtolower($key);
    }

    /**
     * Produce a key that is not already in use.
     *
     * With a format at the entropy floor a collision is vanishingly unlikely, so
     * exhausting ten attempts does not mean bad luck - it means the configured
     * format has far too small a key space, and the exception says so rather
     * than looping indefinitely or returning a duplicate.
     *
     * This is a check, not a reservation: two requests could both find a key
     * free before either stores it. The unique index on the column is what
     * actually prevents a duplicate.
     *
     * @param callable(string):bool $isTaken
     * @throws \RuntimeException When no free key is found within $attempts.
     */
    public function generateUnique(callable $isTaken, int $attempts = 10): string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $key = $this->generate();
            if (!$isTaken($key)) {
                return $key;
            }
        }

        throw new \RuntimeException('Unable to generate a unique license key; check the configured key format.');
    }

    /**
     * Issue a licence key that is free in the database.
     *
     * The convenience entry point used by provisioning and reissuing.
     */
    public static function issue(?string $prefixOverride = null): string
    {
        $generator = self::fromSettings($prefixOverride);

        return $generator->generateUnique(static function (string $key): bool {
            return Db::table('licenses')->where('license_key', $key)->exists();
        });
    }

    /**
     * Reduce a key to its canonical form for comparison.
     *
     * Uppercased and stripped of all whitespace, including the zero-width and
     * byte-order-mark characters that arrive when a customer copies a key out of
     * a PDF, an email or a web page. Those are invisible, so without this a
     * customer would be told their perfectly correct key is invalid with nothing
     * on screen to explain it.
     *
     * Every comparison against a stored key goes through here, so the canonical
     * form is what the unique index and every lookup actually see.
     */
    public static function normalise(string $key): string
    {
        $key = preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $key) ?? '';

        return strtoupper(trim($key));
    }

    /**
     * A key reduced to something safe to keep in history.
     *
     * The live key must stay readable, because findByKey() looks a licence up by
     * equality on it. The historical copies in the reissue log need not, and
     * every extra table holding a usable key is another row a database leak
     * turns into a working credential.
     *
     * Enough tail is kept to answer the question the history exists for: a
     * customer says "my key ended 4WPD and stopped working", and support has to
     * find the reissue that retired it. Four characters do that against one
     * customer's handful of rows while being useless as a credential.
     *
     * Not a security boundary on its own - see the paired hash stored beside it,
     * which identifies a key exactly without storing one.
     */
    public static function mask(string $key): string
    {
        $key = self::normalise($key);
        if ($key === '') {
            return '';
        }

        $tail = substr($key, -4);
        if (strlen($key) <= 4) {
            return str_repeat('*', strlen($key));
        }

        $prefix = '';
        if (preg_match('/^([A-Z0-9]{2,8})-/', $key, $m) === 1) {
            $prefix = $m[1] . '-';
        }

        return $prefix . '****-' . $tail;
    }

    /**
     * Estimate the entropy of a key that was supplied rather than generated.
     *
     * {@see entropyBits()} measures a format this class will emit. A key handed
     * in by an administrator or an importer has no format to consult, so the
     * alphabet is inferred from the characters present and applied to the
     * random-looking part - separators and a prefix are structure, not
     * randomness, and counting them would let `LICENSE-AAAA…` pass on length
     * alone.
     *
     * A deliberate over-estimate of the attacker's work: it assumes every
     * character was chosen uniformly, which a hand-written key never is. It
     * cannot tell that a key is a word or a counter, only that there is not
     * enough material for it to be unguessable even at best. Pair it with
     * {@see looksPatterned()} for the shapes that clear the bit count but are
     * obviously not random.
     */
    public static function entropyOf(string $key): float
    {
        $key = self::normalise($key);
        if ($key === '') {
            return 0.0;
        }

        $body = str_replace(['-', '.', '_'], '', $key);
        if ($body === '') {
            return 0.0;
        }

        if (preg_match('/[A-Z]/', $body) === 1) {
            $alphabet = 32;
        } elseif (preg_match('/[0-9]/', $body) === 1) {
            $alphabet = 10;
        } else {
            return 0.0;
        }

        return strlen($body) * log($alphabet, 2);
    }

    /**
     * Does this key look constructed rather than random?
     *
     * Catches what {@see entropyOf()} cannot: a long key is not a strong one if
     * it is `AAAA-AAAA-AAAA-AAAA` or `CUSTOMER-0001`. Both would clear a bit
     * count computed from length alone.
     *
     * Two crude signals, and crude is appropriate since this only has to catch
     * the obvious cases: too few distinct characters, or one character occupying
     * more than 40% of the key. A genuinely random key of any usable length
     * trips neither.
     */
    public static function looksPatterned(string $key): bool
    {
        $body = str_replace(['-', '.', '_'], '', self::normalise($key));

        if ($body === '') {
            return true;
        }

        $characters = str_split($body);
        $counts     = array_count_values($characters);

        return count($counts) < 6 || (max($counts) / count($characters)) > 0.4;
    }

    /**
     * Could this string be one of our licence keys?
     *
     * A cheap shape check applied before a key reaches the database, so a
     * request carrying something that is not a key at all - an empty value, a
     * sentence, a megabyte of data - is refused without a query. It says nothing
     * about whether the key exists.
     *
     * The 128-character bound matches the column, so an over-long value is
     * rejected rather than silently truncated into a different key.
     */
    public static function looksValid(string $key): bool
    {
        $key = self::normalise($key);

        return $key !== '' && strlen($key) <= 128 && (bool) preg_match('/^[A-Z0-9._\-]+$/', $key);
    }
}
