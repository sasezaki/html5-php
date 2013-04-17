<?php
namespace HTML5\Parser;
require __DIR__ . '/../TestCase.php';
require 'EventStack.php';

class TokenizerTest extends \HTML5\Tests\TestCase {
  // ================================================================
  // Additional assertions.
  // ================================================================
  /**
   * Tests that an event matches both the event type and the expected value.
   *
   * @param string $type
   *   Expected event type.
   * @param string $expects
   *   The value expected in $event['data'][0].
   */
  public function assertEventEquals($type, $expects, $event) {
    $this->assertEquals($type, $event['name'], "Event $type for " . print_r($event, TRUE));
    if (is_array($expects)) {
      $this->assertEquals($expects, $event['data'], "Event $type should equal $expects: " . print_r($event, TRUE));
    }
    else {
      $this->assertEquals($expects, $event['data'][0], "Event $type should equal $expects: " . print_r($event, TRUE));
    }
  }

  /**
   * Assert that a given event is 'error'.
   */
  public function assertEventError($event) {
    $this->assertEquals('error', $event['name'], "Expected error for event: " . print_r($event, TRUE));
  }

  // ================================================================
  // Utility functions.
  // ================================================================

  public function testParse() {
    list($tok, $events) = $this->createTokenizer('');

    $tok->parse();
    $e1 = $events->get(0);

    $this->assertEquals(1, $events->Depth());
    $this->assertEquals('eof', $e1['name']);
  }

  public function testWhitespace() {
    $spaces = '    ';
    list($tok, $events) = $this->createTokenizer($spaces);

    $tok->parse();

    $this->assertEquals(2, $events->depth());

    $e1 = $events->get(0);

    $this->assertEquals('text', $e1['name']);
    $this->assertEquals($spaces, $e1['data'][0]);
  }

  public function testCharacterReference() {
    $str = '&amp;';
    $events = $this->parse($str);

    $this->assertEquals(2, $events->depth());
    $e1 = $events->get(0);

    $this->assertEquals('&', $e1['data'][0]);

    // Test with hex charref
    $str = '&#x003c;';
    $events = $this->parse($str);
    $e1 = $events->get(0);
    $this->assertEquals('<', $e1['data'][0]);

    // Test with decimal charref
    $str = '&#38;';
    $events = $this->parse($str);
    $e1 = $events->get(0);
    $this->assertEquals('&', $e1['data'][0]);

    // Test with stand-alone ampersand
    $str = '& ';
    $events = $this->parse($str);
    $e1 = $events->get(0);
    $this->assertEquals('&', $e1['data'][0][0], "Stand-alone &");

    // Test with broken charref
    $str = '&foo';
    $events = $this->parse($str);
    $e1 = $events->get(0);
    $this->assertEquals('error', $e1['name']);
  }

  public function testBogusComment() {
    $bogus = array(
      '</+this is a bogus comment. +>',
      '<!+this is a bogus comment. !>',
      '<!D OCTYPE foo bar>',
      '<!DOCTYEP foo bar>',
      '<![CADATA[ TEST ]]>',
      '<![CDATA Hello ]]>',
      '<![CDATA[ Hello [[>',
      '<!CDATA[[ test ]]>',
      '<![CDATA[',
      '<![CDATA[hellooooo hello',
      '<? Hello World ?>',
      '<? Hello World',
    );
    foreach ($bogus as $str) {
      $events = $this->parse($str);
      $this->assertEventError($events->get(0));
      $this->assertEventEquals('comment', $str, $events->get(1));
    }
  }

  public function testEndTag() {
    $succeed = array(
      '</a>' => 'a',
      '</test>' => 'test',
      '</test 
      >' => 'test',
      '</thisIsTheTagThatDoesntEndItJustGoesOnAndOnMyFriend>' =>
        'thisIsTheTagThatDoesntEndItJustGoesOnAndOnMyFriend',
      // See 8.2.4.10, which requires this and does not say error.
      '</a<b>' => 'a<b', 
    );
    foreach ($succeed as $test => $result) {
      $events = $this->parse($test);
      $this->assertEquals(2, $events->depth());
      $this->assertEventEquals('endTag', $result, $events->get(0));
    }

    // Recoverable failures
    $fail = array(
      '</a class="monkey">' => 'a',
      '</a <b>' => 'a',
      '</a <b <c>' => 'a',
      '</a is the loneliest letter>' => 'a',
      '</a' => 'a',
    );
    foreach ($fail as $test => $result) {
      $events = $this->parse($test);
      $this->assertEquals(3, $events->depth());
      // Should have triggered an error.
      $this->assertEventError($events->get(0));
      // Should have tried to parse anyway.
      $this->assertEventEquals('endTag', $result, $events->get(1));
    }

    // BogoComments
    $comments = array(
      '</>' => '</>',
      '</ >' => '</ >',
      '</ a>' => '</ a>',
    );
    foreach ($comments as $test => $result) {
      $events = $this->parse($test);
      $this->assertEquals(3, $events->depth());

      // Should have triggered an error.
      $this->assertEventError($events->get(0));

      // Should have tried to parse anyway.
      $this->assertEventEquals('comment', $result, $events->get(1));
    }
  }

  public function testComment() {
    $good = array(
      '<!--easy-->' => 'easy',
      '<!-- 1 > 0 -->' => ' 1 > 0 ',
      '<!-- --$i -->' => ' --$i ',
      '<!----$i-->' => '--$i',
      '<!-- 1 > 0 -->' => ' 1 > 0 ',
      "<!--\nHello World.\na-->" => "\nHello World.\na",
      '<!-- <!-- -->' => ' <!-- ',
    );
    foreach ($good as $test => $expected) {
      $events = $this->parse($test);
      $this->assertEventEquals('comment', $expected, $events->get(0));
    }

    $fail = array(
      '<!-->' => '',
      '<!--Hello' => 'Hello',
      "<!--\0Hello" => UTF8Utils::FFFD . 'Hello',
      '<!--' => '',
    );
    foreach ($fail as $test => $expected) {
      $events = $this->parse($test);
      $this->assertEquals(3, $events->depth());
      $this->assertEventError($events->get(0));
      $this->assertEventEquals('comment', $expected, $events->get(1));
    }

  }

  public function testCDATASection() {
    $good = array(
      '<![CDATA[ This is a test. ]]>' => ' This is a test. ',
      '<![CDATA[CDATA]]>' => 'CDATA',
      '<![CDATA[ ]] > ]]>' => ' ]] > ',
      '<![CDATA[ ]]>' => ' ',
    );
    foreach ($good as $test => $expects) {
      $events = $this->parse($test);
      $this->assertEquals(2, $events->depth(), "Counting events for '$test': " . print_r($events, TRUE));
      $this->assertEventEquals('cdata', $expects, $events->get(0));
    }
  }

  public function testDoctype() {
    $good = array(
      '<!DOCTYPE html>' => array('html', 0, NULL, FALSE),
      "<!DOCTYPE\nhtml>" => array('html', 0, NULL, FALSE),
      "<!DOCTYPE\fhtml>" => array('html', 0, NULL, FALSE),
      '<!DOCTYPE html PUBLIC "foo bar">' => array('html', EventStack::DOCTYPE_PUBLIC, 'foo bar', FALSE),
      "<!DOCTYPE html PUBLIC 'foo bar'>" => array('html', EventStack::DOCTYPE_PUBLIC, 'foo bar', FALSE),
      '<!DOCTYPE      html      PUBLIC     "foo bar"    >' => array('html', EventStack::DOCTYPE_PUBLIC, 'foo bar', FALSE),
      "<!DOCTYPE html \nPUBLIC\n'foo bar'>" => array('html', EventStack::DOCTYPE_PUBLIC, 'foo bar', FALSE),
      '<!DOCTYPE html SYSTEM "foo bar">' => array('html', EventStack::DOCTYPE_SYSTEM, 'foo bar', FALSE),
      "<!DOCTYPE html SYSTEM 'foo bar'>" => array('html', EventStack::DOCTYPE_SYSTEM, 'foo bar', FALSE),
      '<!DOCTYPE      html      SYSTEM "foo/bar"    >' => array('html', EventStack::DOCTYPE_SYSTEM, 'foo/bar', FALSE),
      "<!DOCTYPE html \nSYSTEM\n'foo bar'>" => array('html', EventStack::DOCTYPE_SYSTEM, 'foo bar', FALSE),
    );

    foreach ($good as $test => $expects) {
      $events = $this->parse($test);
      $this->assertEquals(2, $events->depth(), "Counting events for '$test': " . print_r($events, TRUE));
      $this->assertEventEquals('doctype', $expects, $events->get(0));
    }

    $bad = array(
      '<!DOCTYPE>' => array(NULL, EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE    >' => array(NULL, EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE  foo' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE foo PUB' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE foo PUB>' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE  foo PUB "Looks good">' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE  foo SYSTME "Looks good"' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),

      // Can't tell whether these are ids or ID types, since the context is chopped.
      '<!DOCTYPE foo PUBLIC' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE  foo PUBLIC>' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE foo SYSTEM' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),
      '<!DOCTYPE  foo SYSTEM>' => array('foo', EventStack::DOCTYPE_NONE, NULL, TRUE),

      '<!DOCTYPE html SYSTEM "foo bar"' => array('html', EventStack::DOCTYPE_SYSTEM, 'foo bar', TRUE),
      '<!DOCTYPE html SYSTEM "foo bar" more stuff>' => array('html', EventStack::DOCTYPE_SYSTEM, 'foo bar', TRUE),
    );
    foreach ($bad as $test => $expects) {
      $events = $this->parse($test);
      //fprintf(STDOUT, $test . PHP_EOL);
      $this->assertEquals(3, $events->depth(), "Counting events for '$test': " . print_r($events, TRUE));
      $this->assertEventError($events->get(0));
      $this->assertEventEquals('doctype', $expects, $events->get(1));
    }
  }

  public function testProcessorInstruction() {
    $good = array(
      '<?hph ?>' => 'hph',
      '<?hph echo "Hello World"; ?>' => array('hph', 'echo "Hello World"; '), 
      "<?hph \necho 'Hello World';\n?>" => array('hph', "echo 'Hello World';\n"), 
    );
    foreach ($good as $test => $expects) {
      $events = $this->parse($test);
      $this->assertEquals(2, $events->depth(), "Counting events for '$test': " . print_r($events, TRUE));
      $this->assertEventEquals('pi', $expects, $events->get(0));
    }
  }

  /**
   * This tests just simple tags.
   */
  public function testSimpleTags() {
    $open = array(
      '<foo>' => 'foo',
      '<foo >' => 'foo',
      "<foo\n\n\n\n>" => 'foo',
      '<foo:bar>' => 'foo:bar',
    );
    foreach ($open as $test => $expects) {
      $events = $this->parse($test);
      $this->assertEquals(2, $events->depth(), "Counting events for '$test'" . print_r($events, TRUE));
      $this->assertEventEquals('startTag', $expects, $events->get(0));
    }
    $selfClose= array(
      '<foo/>' => 'foo',
      '<foo />' => 'foo',
      "<foo\n\n\n\n/>" => 'foo',
      '<foo:bar/>' => 'foo:bar',
    );
    foreach ($selfClose as $test => $expects) {
      $events = $this->parse($test);
      $this->assertEquals(3, $events->depth(), "Counting events for '$test'" . print_r($events, TRUE));
      $this->assertEventEquals('startTag', $expects, $events->get(0));
      $this->assertEventEquals('endTag', $expects, $events->get(1));
    }

    $bad = array(
      '<foo' => 'foo',
      '<foo ' => 'foo',
      '<foo/' => 'foo',
      '<foo /' => 'foo',
    );

    foreach ($bad as $test => $expects) {
      $events = $this->parse($test);
      $this->assertEquals(3, $events->depth(), "Counting events for '$test': " . print_r($events, TRUE));
      $this->assertEventError($events->get(0));
      $this->assertEventEquals('startTag', $expects, $events->get(1));
    }
  }

  public function testText() {
    $good = array(
      'a<br>b',
      '<a>test</a>',
      'a<![[ test ]]>b',
      'a&amp;b',
    );
    $this->markTestIncomplete("Need tag parsing first.");
  }

  // ================================================================
  // Utility functions.
  // ================================================================
  protected function createTokenizer($string, $debug = FALSE) {
    $eventHandler = new EventStack();
    $stream = new StringInputStream($string);
    $scanner = new Scanner($stream);

    $scanner->debug = $debug;

    return array(
      new Tokenizer($scanner, $eventHandler),
      $eventHandler,
    );
  }

  public function parse($string, $debug = FALSE) {
    list($tok, $events) = $this->createTokenizer($string, $debug);
    $tok->parse();

    return $events;
  }
}
