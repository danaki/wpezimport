<?php
/**
 * Unit tests for taxonomy handling:
 * - wpezimport_process_taxonomy_terms()      (process-import.php)
 * - wpezimport_batch_process_taxonomies()     (import-batch.php, XML)
 * - wpezimport_batch_process_taxonomies_csv() (import-batch.php, CSV)
 *
 * WordPress functions are mocked with WP_Mock; term IDs are simulated in-memory.
 */

class TaxonomyTermsTest extends WP_Mock\Tools\TestCase {

    /** @var array taxonomy => [name => term_id] */
    private $terms;

    /** @var array Log of wp_set_object_terms() calls */
    private $assigned;

    /** @var array Log of wp_insert_term() calls */
    private $inserted;

    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();

        $this->terms    = array();
        $this->assigned = array();
        $this->inserted = array();

        WP_Mock::userFunction('taxonomy_exists', array(
            'return' => function ($tax) {
                return in_array($tax, array('category', 'post_tag', 'product_cat', 'product_tag', 'product_brand'), true);
            },
        ));
        WP_Mock::userFunction('term_exists', array(
            'return' => function ($name, $tax) {
                return isset($this->terms[$tax][$name]) ? array('term_id' => $this->terms[$tax][$name]) : 0;
            },
        ));
        WP_Mock::userFunction('wp_insert_term', array(
            'return' => function ($name, $tax) {
                $id = 100 + count($this->inserted) + 1;
                $this->terms[$tax][$name] = $id;
                $this->inserted[] = array($name, $tax);
                return array('term_id' => $id, 'term_taxonomy_id' => $id);
            },
        ));
        WP_Mock::userFunction('wp_set_object_terms', array(
            'return' => function ($post_id, $ids, $tax, $append) {
                $this->assigned[] = array($post_id, $ids, $tax, $append);
                return $ids;
            },
        ));
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    private function seedTerm($tax, $name, $id) {
        $this->terms[$tax][$name] = $id;
    }

    // =========================================================================
    // wpezimport_process_taxonomy_terms()
    // =========================================================================

    public function testCommaSeparatedTermsAreSplitAndCreated() {
        $result = wpezimport_process_taxonomy_terms(42, 'Bikes,Mountain Bikes, Kids', 'category');

        $this->assertCount(3, $result['terms']);
        $this->assertEmpty($result['errors']);
        $this->assertEquals(array('Bikes', 'Mountain Bikes', 'Kids'), array_column($this->inserted, 0));
        $this->assertCount(1, $this->assigned);
        $this->assertEquals(42, $this->assigned[0][0]);
        $this->assertEquals('category', $this->assigned[0][2]);
        $this->assertTrue($this->assigned[0][3], 'Terms should be appended, not replaced');
    }

    public function testNewlineSeparatedTermsAreSplit() {
        $result = wpezimport_process_taxonomy_terms(1, "A\nB\n\nC", 'post_tag');
        $this->assertCount(3, $result['terms']);
    }

    public function testTermWithSpacesIsSingleTerm() {
        // Regression: space is NOT a delimiter, "Mountain Bikes" must stay one term
        wpezimport_process_taxonomy_terms(1, 'Mountain Bikes', 'category');
        $this->assertEquals(array(array('Mountain Bikes', 'category')), $this->inserted);
    }

    public function testExistingTermIsReusedNotRecreated() {
        $this->seedTerm('category', 'Bikes', 7);

        $result = wpezimport_process_taxonomy_terms(1, 'Bikes,New', 'category');

        $this->assertEquals(array(7, 101), $result['terms']);
        $this->assertEquals(array(array('New', 'category')), $this->inserted);
    }

    public function testMatchExistingOnlySkipsUnknownTerms() {
        $this->seedTerm('product_cat', 'Known', 5);

        $result = wpezimport_process_taxonomy_terms(1, 'Known,Unknown', 'product_cat', true);

        $this->assertEquals(array(5), $result['terms']);
        $this->assertEmpty($this->inserted);
    }

    public function testCurlyBracesAndWhitespaceAreStripped() {
        wpezimport_process_taxonomy_terms(1, ' {Bikes} , {Kids} ', 'category');
        $this->assertEquals(array('Bikes', 'Kids'), array_column($this->inserted, 0));
    }

    public function testUnknownTaxonomyIsSkippedWithError() {
        $result = wpezimport_process_taxonomy_terms(1, 'X', 'no_such_taxonomy');

        $this->assertEmpty($result['terms']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('no_such_taxonomy', $result['errors'][0]);
        $this->assertEmpty($this->inserted);
        $this->assertEmpty($this->assigned);
    }

    public function testEmptyInputReturnsError() {
        $result = wpezimport_process_taxonomy_terms(1, '', 'category');
        $this->assertEmpty($result['terms']);
        $this->assertNotEmpty($result['errors']);

        $result = wpezimport_process_taxonomy_terms(0, 'A', 'category');
        $this->assertEmpty($result['terms']);
    }

    public function testNothingAssignedWhenAllTermsSkipped() {
        wpezimport_process_taxonomy_terms(1, 'A,B', 'category', true);
        $this->assertEmpty($this->assigned);
    }

    // =========================================================================
    // wpezimport_batch_process_taxonomies() — XML
    // =========================================================================

    private function xmlItem() {
        return simplexml_load_string(<<<XML
<product>
    <category>Mountain Bikes</category>
    <category>Kids Bikes</category>
    <tags>
        <tag>sale</tag>
        <tag>new arrival</tag>
    </tags>
    <brand>FREJUS</brand>
</product>
XML
        );
    }

    public function testXmlMultipleCategoriesBecomeSeparateTerms() {
        // Regression for the implode(' ') bug: two <category> nodes must produce two terms
        wpezimport_batch_process_taxonomies(10, $this->xmlItem(), array('post_categories' => '{category}'), 'product', false, false);

        $this->assertEquals(
            array(array('Mountain Bikes', 'product_cat'), array('Kids Bikes', 'product_cat')),
            $this->inserted
        );
    }

    public function testXmlTagsUseProductTagForProducts() {
        wpezimport_batch_process_taxonomies(10, $this->xmlItem(), array('post_tags' => '{tags/tag}'), 'product', false, false);

        $this->assertEquals(array(array('sale', 'product_tag'), array('new arrival', 'product_tag')), $this->inserted);
    }

    public function testXmlUsesCoreTaxonomiesForPosts() {
        wpezimport_batch_process_taxonomies(10, $this->xmlItem(), array(
            'post_categories' => '{category}',
            'post_tags'       => '{tags/tag}',
        ), 'post', false, false);

        $taxes = array_unique(array_column($this->inserted, 1));
        $this->assertEqualsCanonicalizing(array('category', 'post_tag'), $taxes);
    }

    public function testXmlBrandGoesToProductBrand() {
        wpezimport_batch_process_taxonomies(10, $this->xmlItem(), array('post_brand' => '{brand}'), 'product', false, false);

        $this->assertEquals(array(array('FREJUS', 'product_brand')), $this->inserted);
    }

    public function testXmlMatchExistingFlagIsPassedThrough() {
        $this->seedTerm('product_cat', 'Mountain Bikes', 3);

        wpezimport_batch_process_taxonomies(10, $this->xmlItem(), array('post_categories' => '{category}'), 'product', true, false);

        $this->assertEmpty($this->inserted, 'Unknown category must not be created in match-existing mode');
        $this->assertEquals(array(3), $this->assigned[0][1]);
    }

    public function testXmlMultipleExpressionsAreCombined() {
        wpezimport_batch_process_taxonomies(10, $this->xmlItem(), array('post_categories' => '{category} {brand}'), 'product', false, false);

        $this->assertEquals(array('Mountain Bikes', 'Kids Bikes', 'FREJUS'), array_column($this->inserted, 0));
    }

    public function testXmlNoMappingDoesNothing() {
        wpezimport_batch_process_taxonomies(10, $this->xmlItem(), array(), 'product', false, false);
        $this->assertEmpty($this->inserted);
        $this->assertEmpty($this->assigned);
    }

    public function testXmlNonMatchingXpathDoesNothing() {
        wpezimport_batch_process_taxonomies(10, $this->xmlItem(), array('post_categories' => '{nope}'), 'product', false, false);
        $this->assertEmpty($this->assigned);
    }

    // =========================================================================
    // wpezimport_batch_process_taxonomies_csv()
    // =========================================================================

    public function testCsvCategoriesColumnIsSplitByComma() {
        $row = array('Category' => 'Bikes, Kids', 'Tags' => 'sale', 'Brand' => 'FREJUS');

        wpezimport_batch_process_taxonomies_csv(10, $row, array(
            'post_categories' => 'Category',
            'post_tags'       => '{Tags}',
            'post_brand'      => 'Brand',
        ), 'product', false, false);

        $this->assertEquals(array(
            array('Bikes', 'product_cat'),
            array('Kids', 'product_cat'),
            array('sale', 'product_tag'),
            array('FREJUS', 'product_brand'),
        ), $this->inserted);
    }

    public function testCsvEmptyCellDoesNothing() {
        wpezimport_batch_process_taxonomies_csv(10, array('Category' => '  '), array('post_categories' => 'Category'), 'post', false, false);
        $this->assertEmpty($this->assigned);
    }
}
