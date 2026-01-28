# Content Validation Fixer Workflow

## Overview

The Content Validation Fixer workflow automatically improves Drupal articles to meet European Commission content standards. It validates content, generates AI-powered improvement suggestions, shows them to the user, and applies changes upon confirmation.

## Workflow Steps

### 1. Content Context (echack_flowdrop_node_session_content_context.1)
- **Purpose**: Loads the Drupal entity (article) to be validated and fixed
- **Output**: Full entity data including title, body, metatags, and metadata
- **Node Type**: Content Context (square visual)

### 2. Validation Section

#### Prompt Template 2 (prompt_template.2)
- **Purpose**: Builds validation prompt with article content
- **Input**: Entity data (title, body, metatags, metadata)
- **Template**: Extracts and formats article content for validation
- **Output**: Formatted validation request

#### AI Chat Model 1 (flowdrop_ai_provider_chat_model.1)
- **Purpose**: Validates article against EU Commission standards
- **Model**: Mistral Medium (temperature: 0.3 for consistent results)
- **System Prompt**: EU content validator with 10 quality guidelines
- **Input**: Article content to validate
- **Output**: Validation report with scores (1-5) for each guideline
- **Guidelines Evaluated**:
  1. Accuracy & Evidence
  2. Clarity & Plain Language
  3. Neutrality & Objectivity
  4. Source Transparency
  5. Legal & Policy Consistency
  6. Audience Relevance
  7. Structure & Coherence
  8. Completeness & Context
  9. Inclusivity & Language Ethics
  10. Practical Value

#### Chat Output 1 (chat_output.1)
- **Purpose**: Displays validation report to user
- **Format**: Markdown table with scores and suggestions
- **Output**: Validation report (visible to user)

### 3. Improvement Generation Section

#### Prompt Template 3 (prompt_template.3)
- **Purpose**: Builds improvement request prompt
- **Input**: Original entity data (title, body, metatags)
- **Template**: Requests AI to improve article per EU standards
- **Instructions**: Generate JSON with improved title, body, metatags, and explanation
- **Output**: Improvement request prompt

#### AI Chat Model 2 (flowdrop_ai_provider_chat_model.2)
- **Purpose**: Generates specific improvements for the article
- **Model**: Mistral Large (temperature: 0.7 for creative improvements)
- **System Prompt**: Content editor specializing in EU standards
- **Input**: Original article content
- **Output**: JSON object with suggested improvements
- **JSON Structure**:
```json
{
  "title": "Improved title (50-60 chars)",
  "body": "Complete improved body (full HTML)",
  "field_metatags": "Improved meta description (150-160 chars)",
  "explanation": "Summary of changes made"
}
```

#### Chat Output 2 (chat_output.2)
- **Purpose**: Displays suggested improvements to user
- **Format**: Markdown (shows JSON prettified)
- **Output**: Suggested changes (visible to user)

### 4. Confirmation Section

#### Confirmation 1 (confirmation.1)
- **Purpose**: Asks user to approve suggested changes
- **Message**: "Review the suggested changes below and confirm if you want to apply them to your article."
- **Buttons**:
  - "Apply Changes" (confirms)
  - "Cancel" (rejects)
- **Output**: Boolean (true if confirmed, false if cancelled)

#### Boolean Gateway 1 (boolean_gateway.1)
- **Purpose**: Routes workflow based on user decision
- **Input**: Confirmation result (true/false)
- **Branches**:
  - **True**: Proceeds to Entity Save
  - **False**: Workflow ends without saving

### 5. Entity Save Section

#### Entity Save 1 (entity_save.1)
- **Purpose**: Applies improvements to the Drupal article
- **Trigger**: Only executes if user confirmed (True branch)
- **Configuration**:
  - Entity Type: `node`
  - Bundle: `article`
  - Allowed Fields: (empty = all fields allowed)
- **Inputs**:
  - `entity_id`: From Content Context (identifies which article to update)
  - `values`: From AI Chat Model 2 (JSON with improved fields)
- **Output**: Updated entity data
- **Result**: Article is saved with improved title, body, and metatags

## Data Flow Summary

```
Content Context (Load Article)
    ↓
Prompt Template 2 (Build Validation Request)
    ↓
AI Model 1 (Validate Against EU Standards)
    ↓
Chat Output 1 (Show Validation Report)

Content Context (Original Article Data)
    ↓
Prompt Template 3 (Build Improvement Request)
    ↓
AI Model 2 (Generate Improvements)
    ↓
Chat Output 2 (Show Suggested Changes)
    ↓
Confirmation (User Reviews & Approves)
    ↓
Boolean Gateway (Route Based on Decision)
    ↓ (If TRUE)
Entity Save (Apply Improvements to Article)
```

## Key Features

### AI-Powered Validation
- Uses Mistral Medium for consistent, objective validation
- Evaluates against 10 specific EU Commission content guidelines
- Provides scored assessment (1-5) for each guideline
- Includes specific suggestions for improvement

### AI-Powered Improvement
- Uses Mistral Large for creative content improvements
- Generates complete improved versions of:
  - Title (optimized for SEO, clarity, neutrality)
  - Body (full HTML with proper structure and sources)
  - Meta Tags (optimized meta description)
- Provides explanation of changes made

### Human-in-the-Loop
- User must review and approve changes before they're applied
- Displays validation report first
- Shows suggested improvements in readable format
- User can cancel if changes aren't satisfactory

### Safe Entity Updates
- Only updates article if user confirms
- Uses Entity Save node with proper configuration
- Maintains article ID (updates existing article)
- Preserves other fields not being modified

## Usage

### Launch Via Session Context

Navigate to:
```
/admin/flowdrop/workflows/content_validation_fixer/playground/entity?entity_type=node&entity_id=123
```

Replace `123` with your article node ID.

### Launch Via API

```bash
POST /api/flowdrop-node-session/workflows/content_validation_fixer/sessions
Content-Type: application/json

{
  "entity_type": "node",
  "entity_id": "123",
  "bundle": "article"
}
```

### Workflow Steps Experienced by User

1. **Validation Report Displayed**: User sees table with scores and suggestions
2. **Improvement Suggestions Shown**: User sees proposed changes (JSON format)
3. **Confirmation Prompt**: "Review the suggested changes below and confirm if you want to apply them to your article."
4. **User Decision**:
   - Click "Apply Changes": Article is updated with improvements
   - Click "Cancel": Workflow ends, no changes applied
5. **Result**: If confirmed, article is saved with improved content

## Configuration Notes

### AI Models Used

- **Validation AI (Model 1)**: `mistral-medium-latest` with temperature `0.3`
  - Lower temperature for consistent, objective validation
- **Improvement AI (Model 2)**: `mistral-large-latest` with temperature `0.7`
  - Higher temperature for more creative content improvements

### Entity Save Configuration

- **Entity Type**: `node` (configured)
- **Bundle**: `article` (configured)
- **Allowed Fields**: Empty (allows all fields to be updated)
- **Fields Updated**:
  - `title`: Article title
  - `body`: Article body content (HTML)
  - `field_metatags`: Meta tags

### System Prompts

Both AI models have detailed system prompts that define:
- Their role (validator vs. editor)
- The 10 EU Commission content guidelines
- Output format requirements
- Quality standards to enforce

## Troubleshooting

### Issue: JSON Parse Error

**Problem**: Entity Save receives malformed JSON from AI Model 2

**Solution**:
- Check AI Model 2 response in Chat Output 2
- Ensure system prompt emphasizes "pure JSON" output
- May need to add JSON parsing/cleaning step

### Issue: No Body Content

**Problem**: Validation shows "No body content available"

**Solution**:
- Check if article actually has body content
- Verify body field is named `body` (not `field_body`)
- Check entity serialization format from Content Context

### Issue: Changes Not Saved

**Problem**: User confirms but article isn't updated

**Solution**:
- Check Boolean Gateway is routing to Entity Save (True branch)
- Verify entity_id is being passed correctly
- Check Entity Save configuration allows updating the fields
- Review Drupal permissions for the workflow user

### Issue: Validation Report Shows N/A

**Problem**: Most guidelines show N/A instead of scores

**Solution**:
- Body content might not be loading (see "No Body Content" fix)
- Check template uses `{{ body[0].value|raw }}` syntax
- Verify entity is fully serialized with all fields

## Related Workflows

- **Content Validation**: Validates content without making changes
- **Content Generation**: Creates new content from scratch

## Future Enhancements

Potential improvements to consider:

1. **Revision Support**: Create new revision instead of overwriting
2. **Change Preview**: Show diff of original vs. improved content
3. **Partial Updates**: Allow user to accept only some suggested changes
4. **Validation History**: Track validation scores over time
5. **Multi-language Support**: Handle translated content
6. **Custom Guidelines**: Allow configuring which guidelines to enforce
