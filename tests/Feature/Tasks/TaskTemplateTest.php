<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\TaskTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_template_can_be_created(): void
    {
        $template = TaskTemplate::create([
            'name' => 'Foundation Work Template',
            'volume' => 100.50,
            'unit' => 'm³',
            'price' => 150000.00,
            'weight' => 25.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertDatabaseHas('task_templates', [
            'id' => $template->id,
            'name' => 'Foundation Work Template',
            'volume' => 100.50,
            'unit' => 'm³',
        ]);
    }

    public function test_task_template_numeric_fields_are_cast_to_decimal(): void
    {
        $template = TaskTemplate::create([
            'name' => 'Precision Template',
            'volume' => 123.45,
            'price' => 200000.75,
            'weight' => 67.89,
            'unit' => 'm²',
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertEquals(123.45, $template->volume);
        $this->assertEquals(200000.75, $template->price);
        $this->assertEquals(67.89, $template->weight);
    }

    public function test_task_template_has_nested_set_structure(): void
    {
        $parent = TaskTemplate::create([
            'name' => 'Parent Template',
            'volume' => 100.00,
            'unit' => 'm³',
            'price' => 100000.00,
            'weight' => 20.00,
            '_lft' => 1,
            '_rgt' => 4,
        ]);

        $child = TaskTemplate::create([
            'name' => 'Child Template',
            'volume' => 50.00,
            'unit' => 'm³',
            'price' => 50000.00,
            'weight' => 10.00,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $this->assertTrue($child->_lft > $parent->_lft);
        $this->assertTrue($child->_rgt < $parent->_rgt);
        $this->assertEquals($parent->id, $child->parent_id);
    }

    public function test_task_template_parent_relationship(): void
    {
        $parent = TaskTemplate::create([
            'name' => 'Parent Template',
            'volume' => 100.00,
            'unit' => 'm³',
            'price' => 100000.00,
            'weight' => 20.00,
            '_lft' => 1,
            '_rgt' => 4,
        ]);

        $child = TaskTemplate::create([
            'name' => 'Child Template',
            'volume' => 50.00,
            'unit' => 'm³',
            'price' => 50000.00,
            'weight' => 10.00,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $this->assertInstanceOf(TaskTemplate::class, $child->parent);
        $this->assertEquals($parent->id, $child->parent->id);
    }

    public function test_task_template_children_relationship(): void
    {
        $parent = TaskTemplate::create([
            'name' => 'Parent Template',
            'volume' => 100.00,
            'unit' => 'm³',
            'price' => 100000.00,
            'weight' => 20.00,
            '_lft' => 1,
            '_rgt' => 6,
        ]);

        $child1 = TaskTemplate::create([
            'name' => 'Child 1',
            'volume' => 30.00,
            'unit' => 'm³',
            'price' => 30000.00,
            'weight' => 5.00,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $child2 = TaskTemplate::create([
            'name' => 'Child 2',
            'volume' => 40.00,
            'unit' => 'm³',
            'price' => 40000.00,
            'weight' => 8.00,
            'parent_id' => $parent->id,
            '_lft' => 4,
            '_rgt' => 5,
        ]);

        $this->assertCount(2, $parent->children);
        $this->assertTrue($parent->children->contains($child1));
        $this->assertTrue($parent->children->contains($child2));
    }

    public function test_task_template_can_be_root_node(): void
    {
        $root = TaskTemplate::create([
            'name' => 'Root Template',
            'volume' => 100.00,
            'unit' => 'm³',
            'price' => 100000.00,
            'weight' => 20.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertNull($root->parent_id);
        $this->assertNull($root->parent);
    }

    public function test_task_template_can_be_updated(): void
    {
        $template = TaskTemplate::create([
            'name' => 'Old Name',
            'volume' => 50.00,
            'unit' => 'm²',
            'price' => 50000.00,
            'weight' => 10.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $template->update([
            'name' => 'New Name',
            'volume' => 75.00,
            'price' => 75000.00,
        ]);

        $this->assertEquals('New Name', $template->fresh()->name);
        $this->assertEquals(75.00, $template->fresh()->volume);
        $this->assertEquals(75000.00, $template->fresh()->price);
    }

    public function test_task_template_can_be_deleted(): void
    {
        $template = TaskTemplate::create([
            'name' => 'Temporary Template',
            'volume' => 50.00,
            'unit' => 'm²',
            'price' => 50000.00,
            'weight' => 10.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $templateId = $template->id;
        $template->delete();

        $this->assertDatabaseMissing('task_templates', ['id' => $templateId]);
    }

    public function test_task_template_numeric_fields_are_required(): void
    {
        // Volume is required
        $this->expectException(\Illuminate\Database\QueryException::class);

        TaskTemplate::create([
            'name' => 'Missing Volume',
            'volume' => null,
            'unit' => 'm²',
            'price' => 50000.00,
            'weight' => 10.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);
    }

    public function test_task_template_price_is_required(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        TaskTemplate::create([
            'name' => 'Missing Price',
            'volume' => 50.00,
            'unit' => 'm²',
            'price' => null,
            'weight' => 10.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);
    }

    public function test_task_template_weight_is_required(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        TaskTemplate::create([
            'name' => 'Missing Weight',
            'volume' => 50.00,
            'unit' => 'm²',
            'price' => 50000.00,
            'weight' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);
    }

    public function test_task_template_nested_set_boundaries_maintained(): void
    {
        $root = TaskTemplate::create([
            'name' => 'Root',
            'volume' => 100.00,
            'unit' => 'm³',
            'price' => 100000.00,
            'weight' => 20.00,
            '_lft' => 1,
            '_rgt' => 8,
        ]);

        $child1 = TaskTemplate::create([
            'name' => 'Child 1',
            'volume' => 30.00,
            'unit' => 'm³',
            'price' => 30000.00,
            'weight' => 5.00,
            'parent_id' => $root->id,
            '_lft' => 2,
            '_rgt' => 5,
        ]);

        $grandchild = TaskTemplate::create([
            'name' => 'Grandchild',
            'volume' => 10.00,
            'unit' => 'm³',
            'price' => 10000.00,
            'weight' => 2.00,
            'parent_id' => $child1->id,
            '_lft' => 3,
            '_rgt' => 4,
        ]);

        $child2 = TaskTemplate::create([
            'name' => 'Child 2',
            'volume' => 40.00,
            'unit' => 'm³',
            'price' => 40000.00,
            'weight' => 8.00,
            'parent_id' => $root->id,
            '_lft' => 6,
            '_rgt' => 7,
        ]);

        // Verify nested set boundaries
        $this->assertTrue($child1->_lft > $root->_lft && $child1->_rgt < $root->_rgt);
        $this->assertTrue($grandchild->_lft > $child1->_lft && $grandchild->_rgt < $child1->_rgt);
        $this->assertTrue($child2->_lft > $root->_lft && $child2->_rgt < $root->_rgt);
        $this->assertTrue($child2->_lft > $child1->_rgt);
    }

    public function test_task_template_unit_field_is_stored(): void
    {
        $template = TaskTemplate::create([
            'name' => 'Cubic Meter Template',
            'volume' => 100.00,
            'unit' => 'm³',
            'price' => 100000.00,
            'weight' => 20.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertEquals('m³', $template->unit);
    }

    public function test_task_template_supports_different_units(): void
    {
        $template1 = TaskTemplate::create([
            'name' => 'Square Meter Template',
            'volume' => 100.00,
            'unit' => 'm²',
            'price' => 100000.00,
            'weight' => 20.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $template2 = TaskTemplate::create([
            'name' => 'Linear Meter Template',
            'volume' => 50.00,
            'unit' => 'm',
            'price' => 50000.00,
            'weight' => 10.00,
            '_lft' => 3,
            '_rgt' => 4,
        ]);

        $template3 = TaskTemplate::create([
            'name' => 'Kilogram Template',
            'volume' => 200.00,
            'unit' => 'kg',
            'price' => 200000.00,
            'weight' => 5.00,
            '_lft' => 5,
            '_rgt' => 6,
        ]);

        $this->assertEquals('m²', $template1->unit);
        $this->assertEquals('m', $template2->unit);
        $this->assertEquals('kg', $template3->unit);
    }

    public function test_task_template_has_timestamps(): void
    {
        $template = TaskTemplate::create([
            'name' => 'Timestamp Template',
            'volume' => 50.00,
            'unit' => 'm²',
            'price' => 50000.00,
            'weight' => 10.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertNotNull($template->created_at);
        $this->assertNotNull($template->updated_at);
    }

    public function test_task_template_lft_rgt_are_cast_to_integer(): void
    {
        $template = TaskTemplate::create([
            'name' => 'Integer Cast Template',
            'volume' => 50.00,
            'unit' => 'm²',
            'price' => 50000.00,
            'weight' => 10.00,
            '_lft' => 5,
            '_rgt' => 10,
        ]);

        $this->assertIsInt($template->_lft);
        $this->assertIsInt($template->_rgt);
        $this->assertEquals(5, $template->_lft);
        $this->assertEquals(10, $template->_rgt);
    }

    public function test_task_template_parent_id_is_cast_to_integer(): void
    {
        $parent = TaskTemplate::create([
            'name' => 'Parent',
            'volume' => 100.00,
            'unit' => 'm³',
            'price' => 100000.00,
            'weight' => 20.00,
            '_lft' => 1,
            '_rgt' => 4,
        ]);

        $child = TaskTemplate::create([
            'name' => 'Child',
            'volume' => 50.00,
            'unit' => 'm³',
            'price' => 50000.00,
            'weight' => 10.00,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $this->assertIsInt($child->parent_id);
        $this->assertEquals($parent->id, $child->parent_id);
    }
}
