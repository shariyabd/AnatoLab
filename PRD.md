# PRD — Interactive 3D Anatomy Learning Platform

**Document status:** Final / Competition Product PRD  
**Version:** 2.0  
**Primary backend:** Laravel + MySQL  + Inertia + Vue js 
**3D frontend:** Three.js  
**Vector search:** Pinecone (optional; architecture must support a replaceable vector store)  
**Target audience:** Secondary/high-school biology students, approximately ages 13–18  
**Primary goal:** Transform an existing interactive 3D anatomy viewer into a complete, learning-focused, AI-assisted educational application.

---

# 1. Existing Anatomy Foundation / Reference Implementation

This project is not starting the 3D anatomy layer from scratch. The competition platform will be built by evaluating, reusing, adapting, and extending an existing open-source interactive 3D anatomy application.

**Existing repository:** https://github.com/thebuggeddev/anatomy

**Existing application:** https://anatomy-livid.vercel.app/en

## 1.1 Role of the Existing Repository

The existing Anatomy repository is the **starting point for the 3D visualization layer**, not the architectural blueprint for the entire learning platform.

The repository already contains substantial anatomy-specific work, including the existing Three.js-based visualization experience, anatomy models/assets, structure interaction, and related viewer behavior. The new platform should preserve and build on this work wherever it is technically and legally suitable instead of unnecessarily rebuilding equivalent 3D functionality.

The target direction is:

```text
Existing Anatomy Repository
        ↓
3D Anatomy Foundation Audit
        ↓
Extract / Reuse / Adapt viable 3D capabilities
        ↓
Anatomy Core
        ↓
Learning Platform around the 3D Core
        ↓
Laravel + MySQL + AI + RAG + Assessment + Progress
```

## 1.2 Required Repository Audit

Before feature implementation, the existing repository must be inspected to determine:

- Current frontend architecture and dependencies.
- Three.js scene, camera, controls, lighting, loading, raycasting, selection, highlighting, isolation, layers, cross-section, comparison, and animation logic.
- Existing anatomy model formats and asset locations.
- Mesh/object naming conventions.
- Whether individual anatomical structures can be reliably selected.
- Whether stable structure-level identifiers already exist or must be introduced.
- How models map to organs, systems, and anatomical structures.
- Existing data sources and application state.
- Which components can be extracted and reused independently.
- Which parts should be adapted rather than copied directly.
- Which parts should be replaced because they conflict with the new architecture.
- Performance characteristics of the existing models and viewer.
- Browser/device compatibility.
- Existing tests and technical constraints.
- Dependency and build constraints when integrating with the Laravel-based platform.
- Licensing and redistribution rights for all code, models, textures, and other assets.

## 1.3 Reuse Decision Categories

Every significant existing component or asset should be classified as:

```text
REUSE       → use with minimal change
ADAPT       → preserve capability but modify implementation
REPLACE     → rebuild because the existing implementation is unsuitable
UNKNOWN     → requires further investigation
BLOCKED     → cannot be used because of technical or licensing constraints
```

The audit must explicitly document the reason for each decision.

## 1.4 Architectural Boundary

The existing repository and the new Laravel platform may use different technologies or application structures. The project should **reuse the valuable 3D anatomy capabilities without inheriting unsuitable backend or application architecture** from the existing repository.

The 3D layer should expose a clean application-level interface to the learning platform, including concepts such as:

```text
loadOrgan()
selectStructure(structureId)
highlightStructure(structureId)
isolateStructure(structureId)
resetView()
setLayer()
getSelectedStructure()
triggerAnimation()
applySimulationState()
```

The exact interface must be derived from the repository audit and Golden Module rather than invented before understanding the existing implementation.

## 1.5 Licensing as a Release Gate

The project must not assume that an open-source repository automatically means every included model or asset can be redistributed. Code and assets must be audited separately.

For each reused component or asset, record:

- Source.
- Creator/owner where available.
- License.
- Modification rights.
- Redistribution rights.
- Commercial/public-use restrictions.
- Attribution requirements.
- Any third-party dependency restrictions.

If an asset cannot legally support the intended competition/public deployment, it must be replaced, relicensed, or removed.

---

# 2. Product Overview

### 1.1 Product Vision

Build an interactive anatomy learning platform where students learn the human body by **exploring 3D models, completing spatial challenges, asking an AI tutor questions, and interacting with guided simulations**.

The product should not feel like a 3D model gallery with a chatbot attached. The learning experience should revolve around a continuous loop:

> **Explore → Understand → Apply → Test → Improve**

### 1.2 Core Problem

Traditional anatomy learning often depends heavily on:

- 2D textbook diagrams
- Static illustrations
- Memorization
- Passive video consumption
- Multiple-choice quizzes disconnected from spatial understanding

Students can know the definition of an organ without understanding its position, relationships, layers, or function.

### 1.3 Proposed Solution

Use interactive 3D anatomy as the primary learning interface.

A student should be able to:

1. Select an organ or body system.
2. Explore its 3D structure.
3. Isolate anatomical structures.
4. Reveal layers/cross-sections where supported.
5. Read or listen to concise explanations.
6. Ask contextual questions to an AI tutor.
7. Complete 3D spatial challenges.
8. Run simplified educational simulations.
9. Receive personalized feedback.
10. Track mastery over time.

---

# 3. Competition Positioning

## 2.1 One-line Pitch

> **Learn the human body by exploring it — an AI-powered interactive 3D anatomy learning environment for students.**

## 2.2 What Makes It Different

The product combines:

- Interactive 3D anatomy
- Spatial learning
- AI tutoring
- Retrieval-Augmented Generation (RAG)
- Context-aware learning
- Interactive assessment
- Simulation-based learning
- Personalization
- Gamification

The important principle is that these technologies should serve one educational workflow rather than appear as unrelated features.

## 2.3 Competition Demo Story

A judge should be able to understand the product in approximately 3–5 minutes:

1. Open the heart.
2. Rotate the 3D model.
3. Select the left ventricle.
4. Receive a contextual explanation.
5. Complete a spatial question.
6. Ask the AI tutor why the left ventricular wall is thicker.
7. Trigger a “what happens if...” simulation.
8. Complete a short challenge.
9. Show the student's updated mastery score.

---

# 4. Target Audience

## 3.1 Primary Audience

Secondary/high-school biology students, approximately ages 13–18.

### Why this audience

- Anatomy is part of biology education.
- Spatial understanding is important.
- The interface can remain approachable.
- The product can demonstrate measurable learning outcomes.
- The scope remains manageable for a competition project.

## 3.2 Secondary Audience

Future versions may support:

- College/university biology students
- Medical/pre-med students
- Teachers
- Tutors
- Educational institutions

These are not required for the first competition release.

---

# 5. Product Goals

## 4.1 Primary Goals

- Make anatomy learning interactive rather than passive.
- Improve understanding of spatial relationships.
- Provide contextual explanations through AI.
- Ground AI answers in trusted educational content.
- Assess knowledge directly through 3D interaction.
- Personalize learning based on performance.
- Demonstrate a technically impressive but practical architecture.
- Provide measurable learning progress.

## 4.2 Non-goals

The first release should NOT attempt to:

- Diagnose diseases.
- Replace medical education.
- Provide clinical decision-making.
- Become a hospital/medical tool.
- Build a social network.
- Build an unrestricted AI chatbot.
- Support every possible anatomy topic.
- Add AR/VR unless the core web experience is already stable.

---

# 6. Core Product Modules

## 5.1 Explore

Interactive 3D anatomy viewer.

### Requirements

- Rotate model.
- Zoom in/out.
- Pan.
- Reset camera.
- Select anatomical structures.
- Highlight selected structures.
- Isolate selected structures.
- Hide/show structures.
- Layer visibility where supported.
- Cross-section where technically supported.
- Compare structures where meaningful.
- Display anatomical labels.
- Display structure metadata.
- Support desktop and tablet interaction.

### Three.js Responsibilities

Three.js should handle:

- Scene management
- Camera
- Lighting
- Materials
- Model loading
- Raycasting
- Object selection
- Highlighting
- Animation
- Layer visibility
- Camera controls
- 3D interaction state

The backend should NOT attempt to render or manipulate the 3D scene.

---

# 7. Organ and Structure Model

Every interactive anatomical object should have a stable application-level identity.

Example:

```text
organ:
  id: heart

structure:
  id: left_ventricle
  organ_id: heart
  display_name: Left Ventricle
  model_object_name: Heart_LeftVentricle
```

This mapping is critical because the AI, quiz system, analytics system, and frontend all need to refer to the same structure.

## Required metadata

- Stable ID
- Organ ID
- Display name
- Scientific name where applicable
- Short description
- Function
- Location
- Related structures
- System
- Difficulty
- Educational level
- Model object name
- Model file
- Optional animation IDs
- Optional simulation IDs

---

# 8. Learn Module

Learning should be organized around organs and body systems.

## Example

```text
Cardiovascular System
  ├── Heart
  ├── Blood vessels
  └── Blood circulation
```

## Lesson structure

A lesson can contain:

1. Learning objective
2. 3D exploration
3. Guided explanation
4. Interactive activity
5. Knowledge check
6. Reflection/question
7. Mastery update

## Example lesson

### Objective

Understand how blood moves through the heart.

### Activity

Student identifies:

- Right atrium
- Right ventricle
- Pulmonary artery
- Left atrium
- Left ventricle
- Aorta

### Assessment

Student traces the correct blood-flow sequence using the 3D model.

---

# 9. AI Anatomy Tutor

## 8.1 Purpose

Provide contextual educational explanations rather than a generic chatbot.

## 8.2 Context passed to AI

The backend should provide structured context such as:

```json
{
  "organ": "heart",
  "selected_structure": "left_ventricle",
  "lesson": "blood_circulation",
  "difficulty": "intermediate",
  "student_level": "high_school"
}
```

## 8.3 Example

Student selects the left ventricle and asks:

> Why is its wall thicker?

The AI should understand that:

- The user is studying the heart.
- The selected structure is the left ventricle.
- The learner is at a high-school level.
- The answer should be educational and concise.

## 8.4 AI Capabilities

- Explain selected structure.
- Simplify complex concepts.
- Compare two structures.
- Answer contextual questions.
- Generate follow-up questions.
- Give hints.
- Evaluate short answers.
- Provide misconception correction.
- Recommend the next learning activity.

---

# 10. RAG Architecture

RAG is optional at the infrastructure level but strongly recommended for the competition version.

## 9.1 Purpose

Ground educational answers in curated sources.

Potential sources:

- Approved biology textbooks
- Anatomy reference material
- Curriculum-aligned content
- Teacher-created lessons
- Institution-approved educational documents

## 9.2 Pipeline

```text
Educational Sources
        ↓
Document Processing
        ↓
Chunking
        ↓
Embeddings
        ↓
Vector Store
        ↓
Retriever
        ↓
Relevant Context
        ↓
LLM
        ↓
Student Answer
```

## 9.3 Vector Store

The first implementation should keep the vector layer abstract.

Recommended interface:

```php
interface VectorStoreInterface
{
    public function upsert(array $documents): void;

    public function search(
        string $query,
        int $topK = 5,
        array $filters = []
    ): array;

    public function delete(array $ids): void;
}
```

Possible implementation:

```text
PineconeVectorStore
```

Future alternatives:

```text
MySqlVectorStore
PgVectorStore
QdrantVectorStore
```

The application must not directly depend on Pinecone-specific code throughout the domain.

---

# 11. AI + RAG Request Flow

```text
Student
   ↓
Ask Question
   ↓
Laravel API
   ↓
Build Learning Context
   ↓
Retrieve Relevant Knowledge
   ↓
Pinecone (optional)
   ↓
Relevant Chunks
   ↓
LLM Provider
   ↓
Validate Response
   ↓
Return Answer + Sources
   ↓
Store Conversation
```

---

# 12. 3D Spatial Quiz

This should be one of the flagship features.

Instead of only asking:

> Which chamber receives oxygenated blood?

the application displays the heart and asks the student to select the correct structure.

## Flow

```text
Question
   ↓
3D Model
   ↓
Student clicks structure
   ↓
Three.js Raycaster
   ↓
Stable Structure ID
   ↓
Laravel
   ↓
Answer Validation
   ↓
Feedback
   ↓
Progress Update
```

## Requirements

- Clickable anatomical structures.
- Correct/incorrect state.
- Immediate or delayed feedback.
- Explanation after answer.
- Difficulty levels.
- Attempt tracking.
- Time tracking.
- Optional hints.
- Mastery update.

---

# 13. Mission-based Learning

Replace excessive traditional quiz usage with interactive missions.

## Example

### Mission: Trace the Blood

Objective:

> Trace oxygenated blood from the lungs to the body.

Student must select:

```text
Left Atrium
→ Left Ventricle
→ Aorta
→ Body
```

The system validates the sequence.

## Mission types

- Identify a structure.
- Find a structure.
- Trace a pathway.
- Compare structures.
- Arrange a process.
- Predict an outcome.
- Diagnose an educational scenario without making a clinical diagnosis.
- Explain a relationship.

---

# 14. “What Happens If?” Simulations

This is a major opportunity for differentiation.

The system should provide simplified educational simulations.

Examples:

- Valve does not close properly.
- Airflow is obstructed.
- Gas exchange becomes less efficient.
- Blood flow is reduced.
- Insulin is insufficient.
- Neuron signal transmission is interrupted.

## Important scope rule

These are **educational simulations**, not medical diagnostic tools.

## Architecture

```text
Simulation Definition
       ↓
Initial State
       ↓
Student Action
       ↓
State Change
       ↓
3D Visualization
       ↓
AI Explanation
```

Simulation logic should be deterministic where possible. AI should explain the result, not control the underlying simulation.

---

# 15. Personalization

The platform should calculate learning mastery per topic.

Example:

```text
Cardiovascular        82%
Respiratory           74%
Nervous System        51%
Digestive             88%
Endocrine             63%
```

## Personalization inputs

- Quiz accuracy
- Spatial quiz accuracy
- Number of attempts
- Hint usage
- Response quality
- Time spent
- Repeated mistakes
- Completed lessons

## Output

The system may recommend:

> You are strong in organ functions but need more practice identifying anatomical structures.

Then recommend relevant activities.

---

# 16. Gamification

Gamification should reinforce learning rather than distract from it.

Possible features:

- XP
- Levels
- Badges
- Streaks
- Mission completion
- Mastery percentage
- Organ/system achievements

Example badges:

```text
Heart Explorer
Respiratory Specialist
3D Anatomy Scout
Blood Flow Master
Structure Hunter
```

Avoid building a complicated competitive leaderboard initially.

---

# 17. Voice Interaction

Optional Phase 2 feature.

## Flow

```text
Voice Input
   ↓
Speech-to-Text
   ↓
Laravel AI Service
   ↓
RAG + LLM
   ↓
Text Response
   ↓
Text-to-Speech
```

Example:

Student:

> What is this part?

System uses the currently selected structure as context.

---

# 18. Progress Dashboard

Students should see:

- Overall progress.
- System mastery.
- Completed lessons.
- Quiz performance.
- Weak areas.
- Recent activity.
- Recommended lessons.
- Achievements.

Example:

```text
Overall Mastery: 76%

Strongest:
Digestive System — 88%

Needs Practice:
Nervous System — 51%

Recommended:
Neuron Structure Mission
```

---

# 19. Teacher/Admin Module

For the competition MVP, keep this simple.

## Admin capabilities

- Manage organs.
- Manage structures.
- Manage lessons.
- Manage questions.
- Manage missions.
- Manage educational content.
- Manage RAG documents.
- Review AI-generated questions before publishing.
- View basic usage analytics.

## Future teacher capabilities

- Create class.
- Add students.
- Assign lessons.
- Assign missions.
- View class progress.

---

# 20. Backend Architecture

## Recommended stack

```text
Frontend
  React / Next.js / existing frontend
  Three.js

Backend
  Laravel
  PHP

Database
  MySQL

Cache / Queue
  Redis

Vector Search
  Pinecone (optional)

AI
  Provider abstraction

Storage
  S3-compatible storage / local during development
```

## Laravel architecture

Recommended structure:

```text
app/
├── Domain/
│   ├── Anatomy/
│   ├── Learning/
│   ├── Assessment/
│   ├── Simulation/
│   ├── AI/
│   └── Progress/
│
├── Services/
│   ├── Anatomy/
│   ├── Learning/
│   ├── Assessment/
│   ├── AI/
│   ├── RAG/
│   └── Progress/
│
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
│
├── Models/
│
└── Infrastructure/
    ├── AI/
    └── VectorStore/
```

If the existing project already has a different directory convention, preserve the current structure and adapt the concepts rather than forcing this exact layout.

---

# 21. Suggested API Structure

```text
/api/v1

/auth
  POST /login
  POST /register
  POST /logout

/anatomy
  GET /organs
  GET /organs/{organ}
  GET /structures/{structure}

/lessons
  GET /lessons
  GET /lessons/{lesson}
  POST /lessons/{lesson}/complete

/quiz
  GET /quizzes/{quiz}
  POST /quizzes/{quiz}/attempt

/missions
  GET /missions
  GET /missions/{mission}
  POST /missions/{mission}/attempt

/ai
  POST /tutor/ask
  POST /tutor/explain
  POST /tutor/hint

/simulations
  GET /simulations/{simulation}
  POST /simulations/{simulation}/event

/progress
  GET /progress
  GET /progress/systems
  GET /progress/recommendations
```

---

# 22. Database Design

## users

```text
id
name
email
password
education_level
difficulty_preference
created_at
updated_at
```

## organs

```text
id
slug
name
system
description
model_path
thumbnail_path
status
created_at
updated_at
```

## anatomical_structures

```text
id
organ_id
slug
name
scientific_name
model_object_name
description
function
location
difficulty
metadata
created_at
updated_at
```

## lessons

```text
id
organ_id
title
slug
description
difficulty
estimated_minutes
content
status
created_at
updated_at
```

## lesson_progress

```text
id
user_id
lesson_id
status
progress_percent
completed_at
created_at
updated_at
```

## questions

```text
id
lesson_id
type
question
difficulty
explanation
metadata
status
created_at
updated_at
```

## question_options

```text
id
question_id
label
value
is_correct
```

## attempts

```text
id
user_id
question_id
selected_structure_id
selected_option_id
is_correct
time_spent
hint_used
created_at
```

## missions

```text
id
title
slug
description
difficulty
configuration
status
created_at
updated_at
```

## mission_attempts

```text
id
user_id
mission_id
score
completed
duration
result
created_at
updated_at
```

## simulations

```text
id
organ_id
title
slug
description
configuration
status
created_at
updated_at
```

## simulation_sessions

```text
id
user_id
simulation_id
state
events
result
created_at
updated_at
```

## learning_mastery

```text
id
user_id
topic_type
topic_id
mastery_score
attempts
correct_attempts
last_activity_at
created_at
updated_at
```

## conversations

```text
id
user_id
context_type
context_id
title
created_at
updated_at
```

## conversation_messages

```text
id
conversation_id
role
content
metadata
created_at
```

## knowledge_documents

```text
id
title
source
source_type
version
status
metadata
created_at
updated_at
```

## knowledge_chunks

```text
id
document_id
chunk_index
content
embedding_reference
metadata
created_at
updated_at
```

The actual embedding vector does not need to live in MySQL if Pinecone is used.

---

# 23. AI Provider Abstraction

Avoid hardcoding one LLM provider into the application.

```php
interface AIProviderInterface
{
    public function chat(array $messages, array $options = []): AIResponse;

    public function generateEmbedding(string $text): array;
}
```

Potential implementations:

```text
OpenAIProvider
AnthropicProvider
```

Likewise:

```php
interface VectorStoreInterface
{
    public function upsert(array $vectors): void;

    public function search(
        string $query,
        int $topK = 5,
        array $filters = []
    ): array;
}
```

---

# 24. AI Safety and Quality

The AI tutor must:

- Stay within the educational scope.
- Avoid pretending to be a doctor.
- Avoid clinical diagnosis.
- Prefer retrieved educational sources.
- State uncertainty when appropriate.
- Use the student's education level.
- Avoid overwhelming explanations.
- Provide source references where available.

AI-generated educational content should be reviewable before being published as canonical curriculum content.

---

# 25. Performance Requirements

## 3D

- Lazy-load models.
- Load only required organ assets.
- Compress models where possible.
- Use appropriate texture sizes.
- Dispose unused geometries/materials/textures.
- Avoid loading the entire human body on initial page load.
- Use progressive loading where practical.

## Backend

- Cache anatomy metadata.
- Cache frequently accessed lessons.
- Queue expensive AI/RAG processing.
- Use Redis for transient state where useful.
- Avoid synchronous heavy document embedding.

## AI

AI calls should not block unrelated 3D interaction.

---

# 26. Caching Strategy

Possible cache keys:

```text
organ:{id}
structure:{id}
lesson:{id}
quiz:{id}
simulation:{id}
ai_context:{user}:{context}
```

Do not cache personalized AI responses blindly. Cache reusable educational retrieval/context where appropriate.

---

# 27. Queue Jobs

Laravel queues should handle:

```text
ProcessKnowledgeDocument
GenerateEmbeddings
SyncVectorsToPinecone
GenerateLessonQuestions
GenerateRecommendations
ProcessAnalytics
```

The user-facing request should not wait for large document processing.

---

# 28. RAG Retrieval Strategy

A contextual AI request can use:

```text
User Question
+
Selected Organ
+
Selected Structure
+
Lesson
+
Education Level
+
Difficulty
```

Retriever filters can include:

```text
organ_id
structure_id
education_level
content_type
```

Then retrieve top-k chunks.

Example:

```text
Query:
"Why is the left ventricle wall thick?"

Filters:
organ = heart
structure = left_ventricle
level = high_school

Top K:
5
```

---

# 29. Analytics

Track events such as:

```text
organ_viewed
structure_selected
structure_isolated
layer_changed
lesson_started
lesson_completed
question_answered
mission_started
mission_completed
hint_requested
ai_question_asked
simulation_started
simulation_completed
```

Analytics should be designed around learning outcomes, not just page views.

---

# 30. Learning Metrics

Important metrics:

### Engagement

- Sessions per user.
- Session duration.
- Activities completed.

### Learning

- Pre/post quiz improvement.
- Structure identification accuracy.
- Mission completion rate.
- Mastery progression.
- Repeated misconception rate.

### AI

- Questions asked.
- Hint usage.
- AI response feedback.
- RAG retrieval success.

For a university competition, **learning improvement is much stronger evidence than raw feature count**.

---

# 31. Accessibility

The application should support:

- Keyboard navigation for non-3D UI.
- Clear labels.
- High contrast.
- Screen-reader-friendly surrounding content.
- Text alternatives for important educational information.
- Reduced-motion option where appropriate.
- Captions/transcripts for audio.
- Clear interaction feedback.

3D interaction itself should have equivalent textual/structured learning content.

---

# 32. Responsive Design

Priority:

1. Desktop/laptop
2. Tablet
3. Mobile

The 3D viewer should gracefully degrade on lower-powered devices.

The competition demo should be optimized for desktop browsers.

---

# 33. MVP Scope

The MVP should NOT attempt to implement everything.

## MVP includes

### 3D

- Existing anatomy models.
- Organ library.
- Rotate/zoom/pan.
- Structure selection.
- Highlight/isolation.
- Existing layers/cross-section functionality where available.

### Learning

- 3–5 organs.
- 5–10 lessons.
- Structured educational content.

### Assessment

- Standard quiz.
- 3D spatial quiz.
- Basic missions.

### AI

- Context-aware AI tutor.
- RAG-backed answers.
- Source references.
- Hints.

### Progress

- Basic mastery score.
- Activity history.
- Recommended next activity.

### Admin

- Organ management.
- Lesson management.
- Question management.
- Knowledge document management.

---

# 34. Phase 2

After MVP stability:

- Voice tutor.
- More simulations.
- AI-generated questions.
- Teacher dashboard.
- Class management.
- Advanced personalization.
- More organs/body systems.
- Better analytics.

---

# 35. Phase 3

Possible future extensions:

- AR.
- VR.
- Multiplayer classroom mode.
- Mobile application.
- Teacher live sessions.
- Curriculum integrations.
- Institution deployment.

These should not block the competition MVP.

---

# 36. Development Phases

## Phase 0 — Technical Audit

Before feature development:

- Audit live application.
- Audit repository.
- Identify actual 3D model source.
- Identify model formats.
- Inspect mesh/object naming.
- Verify structure-level selection.
- Understand current Three.js architecture.
- Identify current API/data sources.
- Check asset licensing.
- Confirm what is actually reusable.

### Deliverable

A technical reuse report.

---

## Phase 1 — Foundation

- Laravel backend.
- MySQL schema.
- Authentication.
- Anatomy API.
- Organ/structure metadata.
- Frontend/backend integration.
- Stable structure IDs.

---

## Phase 2 — 3D Learning

- Structure selection.
- Learning context.
- Guided exploration.
- Spatial interaction.
- 3D quiz engine.

---

## Phase 3 — Learning System

- Lessons.
- Questions.
- Missions.
- Attempts.
- Mastery calculation.
- Progress dashboard.

---

## Phase 4 — AI Tutor

- AI provider abstraction.
- Context builder.
- Conversation management.
- Prompt system.
- Response validation.

---

## Phase 5 — RAG

- Document ingestion.
- Chunking.
- Embeddings.
- Vector store abstraction.
- Pinecone adapter.
- Retrieval filters.
- Source attribution.

---

## Phase 6 — Simulation

- Simulation definitions.
- Deterministic state engine.
- 3D state changes.
- AI explanations.

---

## Phase 7 — Competition Polish

- Visual design.
- Onboarding.
- Demo journey.
- Performance optimization.
- Error handling.
- Loading states.
- Accessibility.
- Analytics.
- Demo dataset.

---

# 37. Recommended Backend Service Boundaries

```text
AnatomyService
LearningService
LessonService
AssessmentService
MissionService
SimulationService
ProgressService
RecommendationService

AI/
  AITutorService
  AIContextBuilder
  AIResponseValidator

RAG/
  KnowledgeService
  EmbeddingService
  RetrievalService
  VectorStoreInterface
```

Controllers should remain thin.

Example:

```text
Controller
    ↓
Request Validation
    ↓
Service
    ↓
Domain/Model
    ↓
Resource
```

---

# 38. Recommended AI Tutor Flow

```text
POST /api/v1/ai/tutor/ask
        ↓
TutorController
        ↓
Validate Request
        ↓
AITutorService
        ↓
AIContextBuilder
        ↓
Build:
  - user level
  - organ
  - structure
  - lesson
  - recent mistakes
        ↓
RetrievalService
        ↓
VectorStoreInterface
        ↓
Relevant Knowledge
        ↓
AIProviderInterface
        ↓
Response Validator
        ↓
Save Conversation
        ↓
Return Answer + Sources
```

---

# 39. Example Tutor Prompt Context

```text
Student level:
High School

Current organ:
Heart

Selected structure:
Left Ventricle

Current lesson:
Blood Circulation

Learning objective:
Understand the relationship between heart chambers and systemic circulation.

Relevant educational sources:
[retrieved content]

Student question:
Why is the left ventricular wall thicker?
```

The AI should answer at the appropriate educational level.

---

# 40. Error Handling

The system should gracefully handle:

### 3D

- Model loading failure.
- Unsupported device.
- Missing model.
- Invalid structure mapping.

### AI

- Provider timeout.
- Rate limit.
- Invalid response.
- No relevant RAG context.

### RAG

- Vector store unavailable.
- Missing embeddings.
- No relevant documents.

### Backend

- Validation errors.
- Authentication errors.
- Authorization errors.

Never expose internal provider/API errors directly to students.

---

# 41. Security

- Laravel authentication.
- Authorization policies.
- Request validation.
- Rate limiting.
- AI endpoint throttling.
- Admin authorization.
- Secure document upload.
- File type validation.
- File size limits.
- Sanitization of uploaded content.
- API secret protection.
- Do not expose vector-store credentials.
- Do not expose LLM API keys to the browser.

---

# 42. Licensing and Asset Verification

This is a release blocker.

For every existing 3D model, verify:

```text
Model
 ├── Source
 ├── Creator
 ├── License
 ├── Commercial use allowed?
 ├── Modification allowed?
 ├── Redistribution allowed?
 └── Attribution required?
```

University competition use may still involve public deployment, so the asset license must allow the intended usage.

If an asset cannot legally be redistributed, replace it or obtain permission.

---

# 43. Technical Architecture Summary

```text
                    Browser
                       │
        ┌──────────────┴──────────────┐
        │                             │
     Learning UI                  Three.js
        │                             │
        │                        3D Models
        │                             │
        └──────────────┬──────────────┘
                       │
                    Laravel
                       │
      ┌────────────────┼────────────────┐
      │                │                │
    MySQL            Redis             Queue
      │                │                │
      │                │                ├── Embeddings
      │                │                ├── RAG processing
      │                │                └── AI jobs
      │                │
      └────────────────┤
                       │
                AI/RAG Services
                       │
             ┌─────────┴─────────┐
             │                   │
          LLM API             Vector Store
                                 │
                              Pinecone
                              (optional) / Mysqli Native 
```

---

# 44. Definition of Done — Competition MVP

The MVP is considered complete when a new student can:

- Create an account.
- Open an organ.
- Interact with its 3D model.
- Select an anatomical structure.
- Read its explanation.
- Start a lesson.
- Complete a 3D spatial question.
- Ask the AI tutor a contextual question.
- Receive a grounded answer.
- Complete a mission.
- See their score/mastery.
- Receive a recommended next activity.

And a judge can understand the complete product story without needing a technical explanation.

---

# 45. Success Criteria

The project should demonstrate three kinds of success.

## Educational

Students can better identify structures and understand relationships after interacting with the platform.

## Technical

The platform demonstrates:

- Real-time 3D interaction.
- AI integration.
- RAG.
- Context-aware tutoring.
- Interactive assessment.
- Scalable backend architecture.

## Product

The application feels like a coherent educational product rather than a collection of demonstrations.

---

# 46. Final Product Principle

Every feature should answer one question:

> **Does this help the student understand the human body better?**

If the answer is no, it should probably not be in the MVP.

The strongest version of this project is not:

> **“We built a website with Three.js, Laravel, AI and RAG.”**

It is:

> **“We created an interactive learning environment where students can explore the human body in 3D, learn from contextual explanations, test their spatial understanding, experiment with biological processes, and receive personalized guidance from an AI tutor grounded in trusted educational knowledge.”**

That is the product this PRD is designed to build.
